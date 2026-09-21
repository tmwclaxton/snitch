<?php

namespace App\Support;

use App\Models\Post;

class PostCover
{
    /**
     * @var list<string>
     */
    private const PAYLOAD_KEYS = [
        'displayUrl',
        'display_url',
        'originCover',
        'origin_cover',
        'dynamicCover',
        'dynamic_cover',
        'coverUrl',
        'cover_url',
        'thumbnailUrl',
        'thumbnail_url',
        'thumbnail',
        'cover',
    ];

    public static function resolve(Post $post): ?string
    {
        $payload = is_array($post->raw_payload) ? $post->raw_payload : [];

        return self::fromPayload($payload)
            ?? self::fromYoutube($post->url)
            ?? self::fromYoutube($post->media_url)
            ?? self::imageUrl($post->media_url);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private static function fromPayload(array $payload): ?string
    {
        foreach (self::PAYLOAD_KEYS as $key) {
            $url = self::imageUrl($payload[$key] ?? null);

            if ($url !== null) {
                return $url;
            }
        }

        $videoMeta = $payload['videoMeta'] ?? null;

        if (is_array($videoMeta)) {
            $fromMeta = self::fromPayload($videoMeta);

            if ($fromMeta !== null) {
                return $fromMeta;
            }
        }

        $covers = $payload['covers'] ?? null;

        if (is_array($covers)) {
            return self::imageUrl($covers['default'] ?? $covers['origin'] ?? null);
        }

        return null;
    }

    private static function fromYoutube(?string $url): ?string
    {
        $id = self::youtubeId($url);

        if ($id === null) {
            return null;
        }

        return 'https://i.ytimg.com/vi/'.$id.'/hqdefault.jpg';
    }

    private static function youtubeId(?string $url): ?string
    {
        if (! is_string($url) || trim($url) === '') {
            return null;
        }

        $trimmed = trim($url);

        if (preg_match('#youtube\.com/shorts/([A-Za-z0-9_-]{6,})#i', $trimmed, $matches) === 1) {
            return $matches[1];
        }

        if (preg_match('#(?:youtube\.com/watch\?v=|youtu\.be/)([A-Za-z0-9_-]{6,})#i', $trimmed, $matches) === 1) {
            return $matches[1];
        }

        if (preg_match('#youtube\.com/embed/([A-Za-z0-9_-]{6,})#i', $trimmed, $matches) === 1) {
            return $matches[1];
        }

        return null;
    }

    private static function imageUrl(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $url = trim($value);

        if (! str_starts_with($url, 'http://') && ! str_starts_with($url, 'https://')) {
            return null;
        }

        if (preg_match('/\.(mp4|webm|mov|m4v|m3u8)(\?|$)/i', $url) === 1) {
            return null;
        }

        if (str_contains(strtolower($url), 'avatar')) {
            return null;
        }

        return $url;
    }
}
