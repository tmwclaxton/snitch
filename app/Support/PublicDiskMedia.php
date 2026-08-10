<?php

namespace App\Support;

use Illuminate\Support\Facades\Storage;

/**
 * Helpers for media persisted on the public disk (e.g. youtube-media/*.mp4).
 */
class PublicDiskMedia
{
    public static function relativePathFromUrl(string $mediaUrl): ?string
    {
        $path = parse_url($mediaUrl, PHP_URL_PATH);

        if (! is_string($path) || ! str_starts_with($path, '/storage/')) {
            return null;
        }

        $relative = ltrim(substr($path, strlen('/storage/')), '/');

        return $relative !== '' ? $relative : null;
    }

    public static function existsOnPublicDisk(string $mediaUrl): bool
    {
        $relative = self::relativePathFromUrl($mediaUrl);

        return $relative !== null && Storage::disk('public')->exists($relative);
    }

    public static function hostIsNotPubliclyReachable(string $mediaUrl): bool
    {
        $host = strtolower((string) parse_url($mediaUrl, PHP_URL_HOST));

        if ($host === '') {
            return true;
        }

        return in_array($host, ['localhost', '127.0.0.1', '::1', '0.0.0.0'], true)
            || str_ends_with($host, '.local')
            || str_ends_with($host, '.test')
            || str_ends_with($host, '.internal');
    }

    /**
     * NanoGPT cannot fetch localhost APP_URL storage links. Inline a data URI
     * when the file is already on the public disk.
     */
    public static function analyzableUrl(string $mediaUrl): string
    {
        if (! self::hostIsNotPubliclyReachable($mediaUrl) || ! self::existsOnPublicDisk($mediaUrl)) {
            return $mediaUrl;
        }

        $relative = self::relativePathFromUrl($mediaUrl);

        if ($relative === null) {
            return $mediaUrl;
        }

        $binary = Storage::disk('public')->get($relative);

        if (! is_string($binary) || $binary === '') {
            return $mediaUrl;
        }

        $extension = strtolower(pathinfo($relative, PATHINFO_EXTENSION));
        $mime = match ($extension) {
            'webm' => 'video/webm',
            'mov' => 'video/quicktime',
            default => 'video/mp4',
        };

        return 'data:'.$mime.';base64,'.base64_encode($binary);
    }
}
