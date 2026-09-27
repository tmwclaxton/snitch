<?php

namespace App\Support;

/**
 * Instagram engagement helpers shared by Apify and TikHub adapters.
 */
class InstagramMetrics
{
    /**
     * Resolve like count, treating Instagram's hidden-like sentinel as null.
     *
     * When likes are hidden, TikHub/Apify often return 0, -1, or omit the field
     * while views/comments remain real. Store null so engagement math can exclude
     * those posts instead of treating them as zero likes.
     *
     * All-zero rows on larger accounts are also treated as unavailable - stills and
     * carousels often come back with 0/0/0 when metrics are hidden, not genuine zeros.
     *
     * @param  array<string, mixed>  $item
     */
    public static function likesFromPayload(array $item, ?int $accountFollowers = null): ?int
    {
        $raw = null;

        foreach (['like_count', 'likesCount', 'likeCount', 'likes'] as $key) {
            if (array_key_exists($key, $item) && $item[$key] !== null && $item[$key] !== '') {
                $raw = $item[$key];
                break;
            }
        }

        $views = InstagramPostId::viewsFromPayload($item);
        $comments = self::commentsFromPayload($item);
        $followers = $accountFollowers ?? self::followersFromItem($item);

        if ($raw === null) {
            if ($views > 0 || $comments > 0) {
                return null;
            }

            return self::allZeroLooksUnavailable($views, $comments, $followers) ? null : 0;
        }

        if (! is_numeric($raw)) {
            return null;
        }

        $likes = (int) $raw;

        if ($likes < 0) {
            return null;
        }

        if ($likes === 0 && ($views > 0 || $comments > 0)) {
            return null;
        }

        if ($likes === 0 && self::allZeroLooksUnavailable($views, $comments, $followers)) {
            return null;
        }

        return max(0, $likes);
    }

    /**
     * @param  array<string, mixed>  $item
     */
    public static function commentsFromPayload(array $item): int
    {
        foreach (['comment_count', 'commentsCount', 'commentCount', 'comments'] as $key) {
            if (isset($item[$key]) && is_numeric($item[$key])) {
                return max(0, (int) $item[$key]);
            }
        }

        return 0;
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array{views: int, likes: int|null, comments: int, shares: int, clicks: int, like_count_hidden?: bool}
     */
    public static function metricsFromPayload(
        array $item,
        int $shares = 0,
        int $clicks = 0,
        ?int $accountFollowers = null,
    ): array {
        $followers = $accountFollowers ?? self::followersFromItem($item);
        $likes = self::likesFromPayload($item, $followers);
        $metrics = [
            'views' => InstagramPostId::viewsFromPayload($item),
            'likes' => $likes,
            'comments' => self::commentsFromPayload($item),
            'shares' => max(0, $shares),
            'clicks' => max(0, $clicks),
        ];

        return self::withHiddenFlag($metrics);
    }

    /**
     * Re-apply unavailable heuristics to already-mapped metrics using account size.
     *
     * @param  array<string, mixed>  $metrics
     * @return array<string, mixed>
     */
    public static function normalizeMappedMetrics(array $metrics, ?int $accountFollowers = null): array
    {
        $views = max(0, (int) ($metrics['views'] ?? 0));
        $comments = max(0, (int) ($metrics['comments'] ?? 0));
        $likes = array_key_exists('likes', $metrics) ? $metrics['likes'] : 0;

        if ($likes === null || (is_numeric($likes) && (int) $likes < 0)) {
            $metrics['likes'] = null;

            return self::withHiddenFlag($metrics);
        }

        $likes = (int) $likes;

        if ($likes === 0 && ($views > 0 || $comments > 0)) {
            $metrics['likes'] = null;

            return self::withHiddenFlag($metrics);
        }

        if ($likes === 0 && self::allZeroLooksUnavailable($views, $comments, $accountFollowers)) {
            $metrics['likes'] = null;

            return self::withHiddenFlag($metrics);
        }

        $metrics['likes'] = max(0, $likes);
        unset($metrics['like_count_hidden']);

        return $metrics;
    }

    /**
     * @param  array<string, mixed>  $item
     */
    public static function followersFromItem(array $item): ?int
    {
        $candidates = [
            $item['follower_count'] ?? null,
            $item['followers'] ?? null,
            $item['followersCount'] ?? null,
            data_get($item, 'user.follower_count'),
            data_get($item, 'user.followers'),
            data_get($item, 'user.followersCount'),
            data_get($item, 'owner.follower_count'),
            data_get($item, 'owner.followersCount'),
            data_get($item, 'user.edge_followed_by.count'),
        ];

        foreach ($candidates as $value) {
            if (is_numeric($value) && (int) $value >= 0) {
                return (int) $value;
            }
        }

        return null;
    }

    public static function hiddenLikesMinFollowers(): int
    {
        return max(0, (int) config('snitch.sync.hidden_likes_min_followers', 500));
    }

    private static function allZeroLooksUnavailable(int $views, int $comments, ?int $followers): bool
    {
        if ($views > 0 || $comments > 0) {
            return false;
        }

        if ($followers === null) {
            return false;
        }

        return $followers > self::hiddenLikesMinFollowers();
    }

    /**
     * @param  array<string, mixed>  $metrics
     * @return array<string, mixed>
     */
    private static function withHiddenFlag(array $metrics): array
    {
        if (array_key_exists('likes', $metrics) && $metrics['likes'] === null) {
            $metrics['like_count_hidden'] = true;
        } else {
            unset($metrics['like_count_hidden']);
        }

        return $metrics;
    }
}
