<?php

namespace App\Support;

/**
 * Canonical Instagram post identity for corpus dedupe across Apify and TikHub.
 *
 * Both providers often expose a shortcode (URL path) plus a numeric media id.
 * Snitch keys posts on the shortcode so the same reel cannot be stored twice.
 */
class InstagramPostId
{
    /**
     * Prefer shortcode / code over numeric pk / id.
     *
     * @param  array<string, mixed>  $item
     */
    public static function fromPayload(array $item): ?string
    {
        foreach (['shortCode', 'shortcode', 'code'] as $key) {
            $value = $item[$key] ?? null;

            if (is_string($value) && $value !== '') {
                return $value;
            }

            if (is_numeric($value)) {
                return (string) $value;
            }
        }

        $fromUrl = self::fromUrl((string) ($item['url'] ?? ''));

        if ($fromUrl !== null) {
            return $fromUrl;
        }

        foreach (['pk', 'id'] as $key) {
            if (isset($item[$key]) && $item[$key] !== '' && $item[$key] !== null) {
                return (string) $item[$key];
            }
        }

        return null;
    }

    public static function fromUrl(string $url): ?string
    {
        if ($url === '') {
            return null;
        }

        if (preg_match('~instagram\.com/(?:reel|p|tv)/([^/?#]+)~i', $url, $matches) === 1) {
            return $matches[1];
        }

        return null;
    }

    /**
     * Prefer Instagram's play count (real Views) over the legacy 3-second view counter.
     *
     * @param  array<string, mixed>  $item
     */
    public static function viewsFromPayload(array $item): int
    {
        foreach (['videoPlayCount', 'playsCount', 'play_count', 'ig_play_count', 'videoViewCount', 'view_count'] as $key) {
            if (isset($item[$key]) && is_numeric($item[$key])) {
                return max(0, (int) $item[$key]);
            }
        }

        return 0;
    }

    /**
     * Best play-count candidate stored on a post's raw Apify payload.
     *
     * @param  array<string, mixed>|null  $raw
     */
    public static function playCountFromRaw(?array $raw): ?int
    {
        if ($raw === null) {
            return null;
        }

        foreach (['videoPlayCount', 'playsCount', 'play_count', 'ig_play_count'] as $key) {
            if (isset($raw[$key]) && is_numeric($raw[$key]) && (int) $raw[$key] >= 0) {
                return (int) $raw[$key];
            }
        }

        return null;
    }
}
