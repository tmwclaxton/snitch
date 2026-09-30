<?php

namespace App\Services\Dashboard;

use App\Enums\PostType;
use App\Models\Post;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Shared Instagram dashboard formulas (ER, PI, medians, London time, CTAs).
 */
class DashboardMath
{
    public const TIMEZONE = 'Europe/London';

    public const MIN_SAMPLE = 5;

    public const PI_PRIOR_WINDOW = 30;

    public const PI_MIN_PRIORS = 10;

    public const WINNER_THRESHOLD = 2.0;

    public const FLOP_THRESHOLD = 0.5;

    public const FOLLOWER_SNAPSHOT_MAX_DAYS = 14;

    /**
     * @var list<string>
     */
    public const DAYS = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

    /**
     * @var list<array{label: string, start: int, end: int}>
     */
    public const HOUR_BLOCKS = [
        ['label' => '00-04', 'start' => 0, 'end' => 4],
        ['label' => '04-08', 'start' => 4, 'end' => 8],
        ['label' => '08-12', 'start' => 8, 'end' => 12],
        ['label' => '12-16', 'start' => 12, 'end' => 16],
        ['label' => '16-20', 'start' => 16, 'end' => 20],
        ['label' => '20-24', 'start' => 20, 'end' => 24],
    ];

    public function interactions(Post $post): ?int
    {
        $likes = $this->likes($post);

        if ($likes === null) {
            return null;
        }

        return $likes + $this->comments($post);
    }

    public function likes(Post $post): ?int
    {
        if ((bool) data_get($post->metrics, 'like_count_hidden', false)) {
            return null;
        }

        $raw = data_get($post->metrics, 'likes');

        if ($raw === null || $raw === '' || (int) $raw === -1) {
            return null;
        }

        return max(0, (int) $raw);
    }

    public function comments(Post $post): int
    {
        return max(0, (int) data_get($post->metrics, 'comments', 0));
    }

    public function views(Post $post): int
    {
        return max(0, (int) data_get($post->metrics, 'views', 0));
    }

    public function isHiddenLikes(Post $post): bool
    {
        if ((bool) data_get($post->metrics, 'like_count_hidden', false)) {
            return true;
        }

        return $this->likes($post) === null;
    }

    public function isPinned(Post $post): bool
    {
        $payload = $post->raw_payload ?? [];

        return (bool) (
            data_get($payload, 'isPinned')
            || data_get($payload, 'is_pinned')
            || data_get($payload, 'pinned')
        );
    }

    public function formatLabel(Post $post): string
    {
        $type = $post->type instanceof PostType ? $post->type->value : (string) $post->type;
        $hint = strtolower((string) (
            data_get($post->raw_payload, 'productType')
            ?? data_get($post->raw_payload, 'product_type')
            ?? ''
        ));
        $url = strtolower((string) $post->url);

        if (
            $type === PostType::Reel->value
            || str_contains($hint, 'clips')
            || str_contains($url, '/reel')
        ) {
            return 'Reel';
        }

        return match ($type) {
            PostType::Carousel->value, 'Sidecar' => 'Carousel',
            PostType::Image->value, 'Image' => 'Image',
            PostType::Video->value, 'Video' => 'Video',
            default => 'Other',
        };
    }

    /**
     * Engagement rate per follower (%). Null when followers unknown or likes hidden.
     */
    public function engagementRate(Post $post, ?int $followers): ?float
    {
        $interactions = $this->interactions($post);

        if ($interactions === null || $followers === null || $followers <= 0) {
            return null;
        }

        return ($interactions / $followers) * 100;
    }

    /**
     * @param  list<float|int>|Collection<int, float|int>  $values
     */
    public function median(array|Collection $values): ?float
    {
        $sorted = collect($values)
            ->filter(fn (mixed $value): bool => is_numeric($value))
            ->map(fn (mixed $value): float => (float) $value)
            ->sort()
            ->values();

        $count = $sorted->count();

        if ($count === 0) {
            return null;
        }

        $mid = intdiv($count, 2);

        if ($count % 2 === 1) {
            return $sorted[$mid];
        }

        return ($sorted[$mid - 1] + $sorted[$mid]) / 2;
    }

    /**
     * Performance Index: interactions ÷ median of prior posts (min 10 priors preferred).
     *
     * @param  Collection<int, Post>  $accountPostsNewestFirst
     * @return array{pi: float|null, prior_n: int, early: bool}
     */
    public function performanceIndex(Post $post, Collection $accountPostsNewestFirst): array
    {
        $interactions = $this->interactions($post);

        if ($interactions === null || $post->posted_at === null) {
            return ['pi' => null, 'prior_n' => 0, 'early' => true];
        }

        $priors = $accountPostsNewestFirst
            ->filter(function (Post $prior) use ($post): bool {
                if ($prior->id === $post->id || $prior->posted_at === null) {
                    return false;
                }

                if ($this->isHiddenLikes($prior)) {
                    return false;
                }

                if ($prior->posted_at->equalTo($post->posted_at)) {
                    return $prior->id < $post->id;
                }

                return $prior->posted_at->lt($post->posted_at);
            })
            ->sortByDesc(fn (Post $prior) => $prior->posted_at?->getTimestampMs() ?? 0)
            ->take(self::PI_PRIOR_WINDOW)
            ->values();

        $priorInteractions = $priors
            ->map(fn (Post $prior): ?int => $this->interactions($prior))
            ->filter(fn (?int $value): bool => $value !== null)
            ->values();

        $priorN = $priorInteractions->count();
        $median = $this->median($priorInteractions);

        if ($median === null || $median <= 0 || $priorN < 3) {
            return ['pi' => null, 'prior_n' => $priorN, 'early' => true];
        }

        return [
            'pi' => $interactions / $median,
            'prior_n' => $priorN,
            'early' => $priorN < self::PI_MIN_PRIORS,
        ];
    }

    public function toLondon(CarbonImmutable|\DateTimeInterface|string|null $at): ?CarbonImmutable
    {
        if ($at === null) {
            return null;
        }

        return CarbonImmutable::parse($at, 'UTC')->timezone(self::TIMEZONE);
    }

    /**
     * @return array{dow: int, hour: int, block: int}|null dow 0=Mon..6=Sun; block 0..5
     */
    public function londonBucket(?\DateTimeInterface $at): ?array
    {
        $london = $this->toLondon($at);

        if ($london === null) {
            return null;
        }

        $hour = (int) $london->format('G');
        $block = (int) floor($hour / 4);

        return [
            'dow' => ((int) $london->dayOfWeekIso) - 1,
            'hour' => $hour,
            'block' => min(5, max(0, $block)),
        ];
    }

    /**
     * Caption length excluding trailing hashtag block characters.
     */
    public function captionLength(?string $caption): int
    {
        $text = trim((string) $caption);

        if ($text === '') {
            return 0;
        }

        $withoutTags = preg_replace('/(?:\s*#\w+)+\s*$/u', '', $text) ?? $text;

        return mb_strlen(trim($withoutTags));
    }

    public function captionLengthBucket(?string $caption): string
    {
        $n = $this->captionLength($caption);

        return match (true) {
            $n < 50 => '<50',
            $n < 150 => '50-150',
            $n < 500 => '150-500',
            default => '500+',
        };
    }

    public function hashtagCount(?string $caption): int
    {
        preg_match_all('/#\w+/u', (string) $caption, $matches);

        return count($matches[0] ?? []);
    }

    public function mentionCount(?string $caption): int
    {
        preg_match_all('/@\w+/u', (string) $caption, $matches);

        return count($matches[0] ?? []);
    }

    /**
     * @return list<string>
     */
    public function detectCtas(?string $caption): array
    {
        $text = (string) $caption;
        $flags = [];

        if (preg_match('/\?/u', $text) === 1) {
            $flags[] = 'question';
        }

        if (preg_match('/tag (a|your) (friend|mate)/i', $text) === 1) {
            $flags[] = 'tag_friend';
        }

        if (preg_match('/\bcomment\b/i', $text) === 1) {
            $flags[] = 'comment';
        }

        if (preg_match('/save (this|for later)/i', $text) === 1) {
            $flags[] = 'save';
        }

        if (preg_match('/\b(share|send) (this|to)\b/i', $text) === 1) {
            $flags[] = 'share';
        }

        if (preg_match('/link in bio/i', $text) === 1) {
            $flags[] = 'link_in_bio';
        }

        if (preg_match('/\b(dm us|sign up|join us)\b/i', $text) === 1) {
            $flags[] = 'dm_or_signup';
        }

        return array_values(array_unique($flags));
    }

    public function hook(?string $caption): string
    {
        $text = trim((string) $caption);

        if ($text === '') {
            return '';
        }

        // First line only; no ellipsis. Vue cards use a more/less toggle for length.
        $firstLine = preg_split('/\R/u', $text, 2)[0] ?? $text;

        return trim($firstLine);
    }

    /**
     * Lightweight caption theme for community / meetup peers.
     */
    public function classifyTheme(?string $caption): string
    {
        $text = mb_strtolower((string) $caption);

        if ($text === '') {
            return 'other';
        }

        return match (true) {
            (bool) preg_match('/\b(recap|highlights|last (week|night|sunday)|threw back|dump)\b/u', $text) => 'event_recap',
            (bool) preg_match('/\b(join us|this (saturday|sunday|week)|come along|sign up|tickets?|meetup)\b/u', $text) => 'meetup_invite',
            (bool) preg_match('/\b(how to|tips?|guide|ways to|checklist)\b/u', $text) => 'tip_howto',
            (bool) preg_match('/\b(volunteer|food bank|park tidy|charity|donate)\b/u', $text) => 'volunteer',
            (bool) preg_match('/\b(badminton|walk|hike|football|run|gym|board games?|picnic|escape room)\b/u', $text) => 'activity',
            (bool) preg_match('/\b(made a friend|lonel(y|iness)|community|belong)\b/u', $text) => 'social_proof',
            default => 'other',
        };
    }

    public function themeLabel(string $theme): string
    {
        return match ($theme) {
            'event_recap' => 'event recap',
            'meetup_invite' => 'meetup invite',
            'tip_howto' => 'tip / how-to',
            'volunteer' => 'volunteer',
            'activity' => 'activity',
            'social_proof' => 'social proof',
            default => 'other',
        };
    }

    public function hookPattern(?string $hook): string
    {
        $text = trim((string) $hook);

        if ($text === '') {
            return 'plain';
        }

        return match (true) {
            str_contains($text, '?') => 'question',
            (bool) preg_match('/^\d+|^\d+\s|\blist\b/i', $text) => 'number_list',
            (bool) preg_match('/\bpov\b/i', $text) => 'pov',
            (bool) preg_match('/\bfirst time\b/i', $text) => 'first_time',
            (bool) preg_match('/\b(sat|sun|mon|tue|wed|thu|fri|saturday|sunday)\b/i', $text) => 'event_date',
            default => 'plain',
        };
    }

    public function insufficientReason(int $n): string
    {
        return "Not enough posts yet (from {$n} posts)";
    }

    /**
     * Deduplicate posts by shortcode / external_id, keeping the richest row.
     *
     * @param  Collection<int, Post>  $posts
     * @return Collection<int, Post>
     */
    public function dedupePosts(Collection $posts): Collection
    {
        return $posts
            ->groupBy(function (Post $post): string {
                $shortcode = data_get($post->raw_payload, 'shortCode')
                    ?? data_get($post->raw_payload, 'shortcode')
                    ?? data_get($post->raw_payload, 'code');

                if (is_string($shortcode) && $shortcode !== '') {
                    return strtolower($shortcode);
                }

                return (string) $post->external_id;
            })
            ->map(function (Collection $group): Post {
                return $group->sortByDesc(function (Post $post): int {
                    $views = $this->views($post);
                    $likes = $this->likes($post) ?? 0;

                    return ($views * 1000) + $likes + $this->comments($post);
                })->first();
            })
            ->values();
    }

    /**
     * Nearest follower snapshot at or before $at, within 14 days; else latest known.
     *
     * @param  Collection<int, array{captured_on: string, followers: int}>  $snapshots
     */
    public function followersAt(Collection $snapshots, ?\DateTimeInterface $at, ?int $fallback): ?int
    {
        if ($snapshots->isEmpty()) {
            return $fallback !== null && $fallback > 0 ? $fallback : null;
        }

        if ($at === null) {
            $latest = $snapshots->sortByDesc('captured_on')->first();

            return $latest !== null ? (int) $latest['followers'] : $fallback;
        }

        $target = CarbonImmutable::parse($at)->startOfDay();
        $best = null;
        $bestDiff = null;

        foreach ($snapshots as $row) {
            $captured = CarbonImmutable::parse($row['captured_on'])->startOfDay();
            $diff = abs($captured->diffInDays($target));

            if ($diff > self::FOLLOWER_SNAPSHOT_MAX_DAYS) {
                continue;
            }

            if ($bestDiff === null || $diff < $bestDiff || ($diff === $bestDiff && $captured->lte($target))) {
                $best = $row;
                $bestDiff = $diff;
            }
        }

        if ($best !== null) {
            return (int) $best['followers'];
        }

        $latest = $snapshots->sortByDesc('captured_on')->first();

        return $latest !== null ? (int) $latest['followers'] : ($fallback !== null && $fallback > 0 ? $fallback : null);
    }

    public function round1(?float $value): ?float
    {
        return $value === null ? null : round($value, 1);
    }

    public function round2(?float $value): ?float
    {
        return $value === null ? null : round($value, 2);
    }
}
