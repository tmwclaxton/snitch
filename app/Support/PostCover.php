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
        'thumbnailSrc',
        'thumbnail_src',
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

    /**
     * @var list<string>
     */
    private const NESTED_PATHS = [
        'video.cover.url_list.0',
        'video.origin_cover.url_list.0',
        'video.dynamic_cover.url_list.0',
        'video.cover.url',
        'video.originCover',
        'aweme.video.cover.url_list.0',
        'aweme.video.origin_cover.url_list.0',
        'aweme_detail.video.cover.url_list.0',
        'videoMeta.originCover',
        'videoMeta.cover',
        'covers.default',
        'covers.origin',
        'display_resources.0.src',
        'images.0',
        'images.0.url',
        'image',
        'image.uri',
        'media.0.thumbnail',
        'media.0.thumbnailImage.uri',
        'media.0.image.uri',
        'media.0.preferred_thumbnail.image.uri',
        'media.0.preferred_thumbnail.image',
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
            $url = self::stillFromValue($payload[$key] ?? null);

            if ($url !== null) {
                return $url;
            }
        }

        foreach (self::NESTED_PATHS as $path) {
            $url = self::stillFromValue(data_get($payload, $path));

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

        $video = $payload['video'] ?? null;

        if (is_array($video)) {
            $fromVideo = self::fromPayload($video);

            if ($fromVideo !== null) {
                return $fromVideo;
            }
        }

        $covers = $payload['covers'] ?? null;

        if (is_array($covers)) {
            return self::stillFromValue($covers['default'] ?? $covers['origin'] ?? null);
        }

        return null;
    }

    private static function stillFromValue(mixed $value): ?string
    {
        $direct = self::imageUrl($value);

        if ($direct !== null) {
            return $direct;
        }

        if (! is_array($value)) {
            return null;
        }

        return self::imageUrl($value['url_list'][0] ?? null)
            ?? self::imageUrl($value['url'] ?? null)
            ?? self::imageUrl($value['uri'] ?? null)
            ?? self::imageUrl($value['src'] ?? null)
            ?? self::imageUrl($value[0] ?? null);
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
