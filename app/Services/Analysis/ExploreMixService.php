<?php

namespace App\Services\Analysis;

use App\Models\Post;

/**
 * Quality-biased Explore ordering: prefer stronger posts, rotate among them,
 * and keep weak ones from dominating the front of the catalogue.
 */
class ExploreMixService
{
    /**
     * Quality signal for Explore mixing. Winner score wins when present;
     * otherwise a soft engagement proxy so unscored reels are not all equal.
     */
    public function qualityScore(Post $post): float
    {
        $winnerScore = $post->winnerInsight?->score;

        if (is_numeric($winnerScore) && (float) $winnerScore > 0) {
            return (float) $winnerScore;
        }

        $metrics = is_array($post->metrics) ? $post->metrics : [];
        $views = max(0, (int) ($metrics['views'] ?? 0));
        $likes = max(0, (int) ($metrics['likes'] ?? 0));
        $comments = max(0, (int) ($metrics['comments'] ?? 0));
        $shares = max(0, (int) ($metrics['shares'] ?? 0));

        if ($views === 0 && $likes === 0 && $comments === 0 && $shares === 0) {
            return 1.0;
        }

        $engagement = $likes + $comments + $shares;
        $rateBonus = $views > 0 ? min(40.0, ($engagement / $views) * 100) : 0.0;

        return round(
            min(100.0, log10(max(1, $views)) * 25)
            + min(30.0, log10(max(1, $likes)) * 12)
            + ($rateBonus * 0.35),
            2,
        );
    }

    /**
     * Explore seed policy:
     * - explicit explore_seed always wins (pagination / filter session)
     * - bare /explore (no query params): fresh seed every full visit
     * - any other query (filters, page, …) without seed: stable 6h bucket
     */
    public function resolveSeed(
        mixed $raw,
        int $userId,
        bool $hasQueryParams = false,
        ?int $entropy = null,
        ?int $now = null,
    ): int {
        $explicit = $this->parseExplicitSeed($raw);

        if ($explicit !== null) {
            return $explicit;
        }

        if (! $hasQueryParams) {
            return $this->freshSeed($userId, $entropy);
        }

        return $this->seedFor($userId, $now);
    }

    /**
     * New seed for a bare Explore visit (no query string).
     */
    public function freshSeed(int $userId, ?int $entropy = null): int
    {
        $entropy ??= (int) (microtime(true) * 1_000_000);

        return (int) sprintf('%u', crc32('explore|'.$userId.'|'.$entropy));
    }

    /**
     * Stable seed within the configured hour bucket (default 6h).
     */
    public function seedFor(int $userId, ?int $now = null): int
    {
        $bucketHours = max(1, (int) config('snitch.explore.seed_bucket_hours', 6));
        $now ??= time();
        $bucket = (int) floor($now / ($bucketHours * 3600));

        return (int) sprintf('%u', crc32('explore|'.$userId.'|'.$bucket));
    }

    public function parseExplicitSeed(mixed $raw): ?int
    {
        if (is_int($raw) || (is_string($raw) && preg_match('/^-?\d+$/', $raw) === 1)) {
            return (int) sprintf('%u', (int) $raw);
        }

        if (is_numeric($raw)) {
            return (int) sprintf('%u', (int) $raw);
        }

        return null;
    }

    /**
     * Reorder id => score maps: strong posts get a weighted shuffle; weak posts
     * trail in descending score order so junk does not lead the grid.
     *
     * @param  array<int, float>  $scoredIds
     * @return list<int>
     */
    public function mix(array $scoredIds, int $seed): array
    {
        if ($scoredIds === []) {
            return [];
        }

        if (! (bool) config('snitch.explore.mix_enabled', true)) {
            arsort($scoredIds, SORT_NUMERIC);

            return array_map('intval', array_keys($scoredIds));
        }

        $max = max($scoredIds);
        $ratio = max(0.0, min(1.0, (float) config('snitch.explore.min_quality_ratio', 0.35)));
        $floor = $max * $ratio;

        $strong = [];
        $weak = [];

        foreach ($scoredIds as $id => $score) {
            $id = (int) $id;
            $score = (float) $score;

            if ($score >= $floor) {
                $strong[$id] = $score;
            } else {
                $weak[$id] = $score;
            }
        }

        if ($strong === []) {
            $strong = $scoredIds;
            $weak = [];
        }

        $mixedStrong = $this->rotateIds(
            $this->weightedShuffle($strong, $seed),
            $seed,
        );

        arsort($weak, SORT_NUMERIC);
        $weakIds = array_map('intval', array_keys($weak));

        return [...$mixedStrong, ...$weakIds];
    }

    /**
     * Soft-rank similarity hits: exact matches stay pinned (lightly shuffled),
     * remaining related posts get the same quality-biased mix.
     *
     * @param  array<int, float>  $scoredIds
     * @param  list<int>  $pinnedIds
     * @return list<int>
     */
    public function mixSemantic(array $scoredIds, array $pinnedIds, int $seed): array
    {
        $pinned = [];
        foreach ($pinnedIds as $id) {
            $id = (int) $id;
            if (isset($scoredIds[$id])) {
                $pinned[$id] = (float) $scoredIds[$id];
                unset($scoredIds[$id]);
            } else {
                $pinned[$id] = 1.0;
            }
        }

        $pinnedOrder = $this->rotateIds(
            $this->weightedShuffle($pinned, $seed ^ 0x9E3779B9),
            $seed ^ 0x9E3779B9,
        );
        $rest = $this->mix($scoredIds, $seed);

        return [...$pinnedOrder, ...$rest];
    }

    /**
     * @param  array<int, float>  $scoredIds
     * @return list<int>
     */
    private function weightedShuffle(array $scoredIds, int $seed): array
    {
        if ($scoredIds === []) {
            return [];
        }

        if (count($scoredIds) === 1) {
            return [(int) array_key_first($scoredIds)];
        }

        $exponent = max(0.1, (float) config('snitch.explore.weight_exponent', 1.5));
        $jitter = max(0.0, min(1.0, (float) config('snitch.explore.jitter', 0.65)));

        $keys = [];
        foreach ($scoredIds as $id => $score) {
            $id = (int) $id;
            $weight = max(0.001, ((float) $score) ** $exponent);
            $noise = $this->unitNoise($seed, $id);
            // Gumbel-ish key: higher weight wins more often; jitter rotates ties.
            $keys[$id] = log($weight) + ($jitter * -log(max(1e-9, 1.0 - $noise)));
        }

        arsort($keys, SORT_NUMERIC);

        return array_map('intval', array_keys($keys));
    }

    /**
     * Extra reload variation: rotate the already quality-biased list by a
     * seeded offset so the starting card moves without chaos.
     *
     * @param  list<int>  $ids
     * @return list<int>
     */
    private function rotateIds(array $ids, int $seed): array
    {
        $count = count($ids);

        if ($count < 2) {
            return $ids;
        }

        $offset = (int) floor($this->unitNoise($seed ^ 0xA5A5A5A5, $count) * $count);

        if ($offset === 0) {
            return $ids;
        }

        return [...array_slice($ids, $offset), ...array_slice($ids, 0, $offset)];
    }

    private function unitNoise(int $seed, int $postId): float
    {
        $hash = (int) sprintf('%u', crc32($seed.'|'.$postId));

        return ($hash % 1_000_000) / 1_000_000;
    }
}
