<?php

namespace App\Services\Analysis;

use App\Models\Post;
use App\Models\PostAnalysis;
use App\Support\CosineSimilarity;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class AnalysisEmbeddingService
{
    public function __construct(
        private NanoGptClient $client,
    ) {}

    public function enabled(): bool
    {
        return (bool) config('snitch.embeddings.enabled', true)
            && (string) config('snitch.nanogpt.api_key') !== '';
    }

    public function model(): string
    {
        return (string) config('snitch.embeddings.model', 'text-embedding-3-small');
    }

    public function buildSourceText(PostAnalysis $analysis, ?Post $post = null): string
    {
        $post ??= $analysis->relationLoaded('post') ? $analysis->post : $analysis->post()->first();

        $parts = [
            $analysis->concept,
            $analysis->hook,
            $analysis->idea,
            $analysis->visual_summary,
            is_array($analysis->topics) ? implode(', ', $analysis->topics) : null,
            is_array($analysis->custom_tags) ? implode(', ', $analysis->custom_tags) : null,
            $this->howToCopySnippet($analysis->how_to_copy),
            $post?->caption,
        ];

        $text = collect($parts)
            ->map(fn (mixed $part): string => trim((string) $part))
            ->filter(fn (string $part): bool => $part !== '')
            ->implode("\n");

        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }

    public function hashSourceText(string $text): string
    {
        return hash('sha256', $this->model()."\n".$text);
    }

    /**
     * Persist an embedding for the analysis when the source text changed.
     *
     * @return bool True when a new embedding was written.
     */
    public function embedAnalysis(PostAnalysis $analysis, bool $force = false): bool
    {
        if (! $this->enabled()) {
            return false;
        }

        $text = $this->buildSourceText($analysis);

        if ($text === '') {
            return false;
        }

        $hash = $this->hashSourceText($text);

        if (
            ! $force
            && $analysis->embedding_hash === $hash
            && $analysis->embedding_model === $this->model()
            && is_array($analysis->embedding)
            && $analysis->embedding !== []
        ) {
            return false;
        }

        $vector = $this->embedText($text);

        $analysis->forceFill([
            'embedding' => $vector,
            'embedding_model' => $this->model(),
            'embedding_hash' => $hash,
        ])->save();

        return true;
    }

    /**
     * @return list<float>|null
     */
    public function embedQuery(string $query): ?array
    {
        $trimmed = trim($query);

        if ($trimmed === '' || ! $this->enabled()) {
            return null;
        }

        try {
            return $this->embedText($trimmed);
        } catch (Throwable $e) {
            Log::warning('Explore query embedding failed', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Rank posts by cosine similarity to the query vector.
     *
     * @param  Collection<int, Post>  $posts
     * @param  list<float>  $queryVector
     * @param  list<int>  $boostedPostIds  Always kept (e.g. exact custom_tag matches) with score 1.0
     * @return list<int> Ordered post IDs
     */
    public function rankPostIds(Collection $posts, array $queryVector, array $boostedPostIds = []): array
    {
        $minSimilarity = (float) config('snitch.embeddings.min_similarity', 0.22);
        $boosted = array_fill_keys($boostedPostIds, true);
        $scored = [];

        foreach ($posts as $post) {
            $postId = (int) $post->id;

            if (isset($boosted[$postId])) {
                $scored[$postId] = 1.0;

                continue;
            }

            $embedding = $post->analysis?->embedding;

            if (! is_array($embedding) || $embedding === []) {
                continue;
            }

            $score = CosineSimilarity::score($queryVector, $embedding);

            if ($score < $minSimilarity) {
                continue;
            }

            $scored[$postId] = $score;
        }

        foreach ($boostedPostIds as $postId) {
            if (! isset($scored[$postId])) {
                $scored[$postId] = 1.0;
            }
        }

        uasort($scored, function (float $a, float $b): int {
            if ($a === $b) {
                return 0;
            }

            return $a < $b ? 1 : -1;
        });

        return array_map('intval', array_keys($scored));
    }

    /**
     * @return list<float>
     */
    private function embedText(string $text): array
    {
        $vectors = $this->client->embeddings($text, $this->model());
        $vector = $vectors[0] ?? null;

        if (! is_array($vector) || $vector === []) {
            throw new RuntimeException('NanoGPT returned no embedding vector.');
        }

        return $vector;
    }

    private function howToCopySnippet(?string $howToCopy): ?string
    {
        $trimmed = trim((string) $howToCopy);

        if ($trimmed === '') {
            return null;
        }

        if (mb_strlen($trimmed) <= 400) {
            return $trimmed;
        }

        return mb_substr($trimmed, 0, 400);
    }
}
