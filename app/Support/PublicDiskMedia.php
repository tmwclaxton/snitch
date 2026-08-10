<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

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
     * when the file is already on the public disk. Oversized files are
     * transcoded down so the request body stays under NanoGPT's ~4.4MB cap.
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

        $binary = self::binaryForInlineAnalysis($relative);

        $extension = strtolower(pathinfo($relative, PATHINFO_EXTENSION));
        $mime = match ($extension) {
            'webm' => 'video/webm',
            'mov' => 'video/quicktime',
            default => 'video/mp4',
        };

        return 'data:'.$mime.';base64,'.base64_encode($binary);
    }

    public static function maxInlineDataUriBytes(): int
    {
        return max(1, (int) config('snitch.video_analysis.max_inline_data_uri_bytes', 4_200_000));
    }

    /**
     * Estimated data-URI length for a raw file size (base64 + short mime prefix).
     */
    public static function estimatedDataUriBytes(int $rawBytes): int
    {
        return (int) ceil($rawBytes * 4 / 3) + 64;
    }

    private static function binaryForInlineAnalysis(string $relative): string
    {
        $disk = Storage::disk('public');
        $rawBytes = (int) $disk->size($relative);
        $maxDataUri = self::maxInlineDataUriBytes();

        if (self::estimatedDataUriBytes($rawBytes) <= $maxDataUri) {
            $binary = $disk->get($relative);

            if (! is_string($binary) || $binary === '') {
                throw new RuntimeException('Unable to read public-disk media for NanoGPT inline analysis.');
            }

            return $binary;
        }

        Log::info('PublicDiskMedia compressing oversized media for NanoGPT inline analysis', [
            'path' => $relative,
            'bytes' => $rawBytes,
            'max_data_uri_bytes' => $maxDataUri,
        ]);

        return self::transcodeToFitDataUriLimit($relative, $maxDataUri);
    }

    private static function transcodeToFitDataUriLimit(string $relative, int $maxDataUriBytes): string
    {
        $source = Storage::disk('public')->path($relative);

        if (! is_file($source)) {
            throw new RuntimeException('Public-disk media path is missing for NanoGPT inline analysis.');
        }

        $ffmpeg = (string) config('snitch.video_analysis.ffmpeg_binary', 'ffmpeg');
        $maxRawBytes = max(32_000, (int) floor(($maxDataUriBytes - 64) * 3 / 4));

        // Progressively more aggressive encodes until the payload fits.
        $attempts = [
            ['scale' => 480, 'crf' => 28, 'audio_bitrate' => '64k'],
            ['scale' => 360, 'crf' => 32, 'audio_bitrate' => '48k'],
            ['scale' => 360, 'crf' => 36, 'audio_bitrate' => '32k'],
        ];

        foreach ($attempts as $attempt) {
            $output = tempnam(sys_get_temp_dir(), 'snitch-inline-');

            if ($output === false) {
                throw new RuntimeException('Unable to allocate temp file for NanoGPT media compression.');
            }

            $target = $output.'.mp4';
            @unlink($output);

            $result = Process::timeout(180)->run([
                $ffmpeg,
                '-y',
                '-i',
                $source,
                '-vf',
                "scale='min({$attempt['scale']},iw)':-2",
                '-c:v',
                'libx264',
                '-preset',
                'veryfast',
                '-crf',
                (string) $attempt['crf'],
                '-c:a',
                'aac',
                '-b:a',
                $attempt['audio_bitrate'],
                '-movflags',
                '+faststart',
                $target,
            ]);

            if (! $result->successful() || ! is_file($target)) {
                @unlink($target);

                $error = strtolower($result->errorOutput().$result->output());
                $missingBinary = in_array($result->exitCode(), [126, 127], true)
                    || str_contains($error, 'not found')
                    || str_contains($error, 'no such file');

                if ($missingBinary) {
                    throw new RuntimeException(
                        'ffmpeg is required to compress oversized local media for NanoGPT analysis.',
                    );
                }

                continue;
            }

            $size = filesize($target);

            if (! is_int($size) || $size <= 0 || $size > $maxRawBytes) {
                @unlink($target);

                continue;
            }

            $binary = file_get_contents($target);
            @unlink($target);

            if (! is_string($binary) || $binary === '') {
                continue;
            }

            return $binary;
        }

        throw new RuntimeException(
            'Local media is too large for NanoGPT inline analysis even after compression.',
        );
    }
}
