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
     * @param  array<string, mixed>  $item
     */
    public static function likesFromPayload(array $item): ?int
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

        if ($raw === null) {
            return ($views > 0 || $comments > 0) ? null : 0;
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
    public static function metricsFromPayload(array $item, int $shares = 0, int $clicks = 0): array
    {
        $likes = self::likesFromPayload($item);
        $metrics = [
            'views' => InstagramPostId::viewsFromPayload($item),
            'likes' => $likes,
            'comments' => self::commentsFromPayload($item),
            'shares' => max(0, $shares),
            'clicks' => max(0, $clicks),
        ];

        if ($likes === null) {
            $metrics['like_count_hidden'] = true;
        }

        return $metrics;
    }
}
