<?php

namespace App\Support;

use App\Enums\Platform;

class PlatformEmbed
{
    /**
     * Build an official platform player iframe config from a post URL.
     *
     * @return array{
     *     provider: string,
     *     src: string,
     *     title: string,
     *     aspect: string
     * }|null
     */
    public static function resolve(
        Platform|string|null $platform,
        ?string $url,
        bool $compact = false,
    ): ?array {
        if ($url === null || trim($url) === '') {
            return null;
        }

        $platformEnum = $platform instanceof Platform
            ? $platform
            : Platform::tryFrom((string) $platform);

        if ($platformEnum === null) {
            return null;
        }

        $trimmed = trim($url);

        return match ($platformEnum) {
            Platform::TikTok => self::tikTok($trimmed),
            Platform::Instagram => self::instagram($trimmed, $compact),
            Platform::Facebook => self::facebook($trimmed),
            Platform::LinkedIn => self::linkedIn($trimmed),
        };
    }

    /**
     * @return array{provider: string, src: string, title: string, aspect: string}|null
     */
    private static function tikTok(string $url): ?array
    {
        if (! preg_match('#tiktok\.com/.*/video/(\d+)#i', $url, $matches)
            && ! preg_match('#tiktok\.com/@[^/]+/video/(\d+)#i', $url, $matches)
            && ! preg_match('#/(?:embed|player)/v[12]/(\d+)#i', $url, $matches)) {
            return null;
        }

        $id = $matches[1];

        return [
            'provider' => 'tiktok',
            'src' => 'https://www.tiktok.com/player/v1/'.$id.'?music_info=0&description=0&autoplay=0',
            'title' => 'TikTok video',
            'aspect' => '9/16',
        ];
    }

    /**
     * @return array{provider: string, src: string, title: string, aspect: string}|null
     */
    private static function instagram(string $url, bool $compact = false): ?array
    {
        if (! preg_match('#instagram\.com/(p|reel|reels|tv)/([A-Za-z0-9_-]+)#i', $url, $matches)) {
            return null;
        }

        $kind = strtolower($matches[1]) === 'reels' ? 'reel' : strtolower($matches[1]);
        $code = $matches[2];
        $path = $kind === 'tv' ? 'tv' : ($kind === 'reel' ? 'reel' : 'p');
        $suffix = $compact ? 'embed/' : 'embed/captioned/';

        return [
            'provider' => 'instagram',
            'src' => "https://www.instagram.com/{$path}/{$code}/{$suffix}",
            'title' => 'Instagram post',
            'aspect' => '4/5',
        ];
    }

    /**
     * @return array{provider: string, src: string, title: string, aspect: string}|null
     */
    private static function facebook(string $url): ?array
    {
        if (! preg_match('#facebook\.com|fb\.watch|fb\.com#i', $url)) {
            return null;
        }

        $isVideo = (bool) preg_match('#/(videos|watch|reel)/|video\.php|fb\.watch#i', $url);
        $encoded = rawurlencode($url);

        if ($isVideo) {
            return [
                'provider' => 'facebook',
                'src' => 'https://www.facebook.com/plugins/video.php?href='.$encoded.'&show_text=false&width=500',
                'title' => 'Facebook video',
                'aspect' => '9/16',
            ];
        }

        return [
            'provider' => 'facebook',
            'src' => 'https://www.facebook.com/plugins/post.php?href='.$encoded.'&show_text=true&width=500',
            'title' => 'Facebook post',
            'aspect' => '4/5',
        ];
    }

    /**
     * @return array{provider: string, src: string, title: string, aspect: string}|null
     */
    private static function linkedIn(string $url): ?array
    {
        if (preg_match('~linkedin\.com/embed/feed/update/(urn:li:[^/?#]+)~i', $url, $matches)) {
            $urn = $matches[1];

            return [
                'provider' => 'linkedin',
                'src' => 'https://www.linkedin.com/embed/feed/update/'.$urn,
                'title' => 'LinkedIn post',
                'aspect' => '4/5',
            ];
        }

        if (preg_match('~linkedin\.com/feed/update/(urn:li:[^/?#]+)~i', $url, $matches)) {
            $urn = $matches[1];

            return [
                'provider' => 'linkedin',
                'src' => 'https://www.linkedin.com/embed/feed/update/'.$urn,
                'title' => 'LinkedIn post',
                'aspect' => '4/5',
            ];
        }

        // activity-XXXXXXXX from /posts/...-activity-XXXXXXXX-...
        if (preg_match('~linkedin\.com/posts/[^/?#]*activity-(\d+)~i', $url, $matches)) {
            $activityId = $matches[1];

            return [
                'provider' => 'linkedin',
                'src' => 'https://www.linkedin.com/embed/feed/update/urn:li:activity:'.$activityId,
                'title' => 'LinkedIn post',
                'aspect' => '4/5',
            ];
        }

        if (preg_match('#urn:li:(activity|share|ugcPost):(\d+)#i', $url, $matches)) {
            $urn = 'urn:li:'.$matches[1].':'.$matches[2];

            return [
                'provider' => 'linkedin',
                'src' => 'https://www.linkedin.com/embed/feed/update/'.$urn,
                'title' => 'LinkedIn post',
                'aspect' => '4/5',
            ];
        }

        return null;
    }
}
