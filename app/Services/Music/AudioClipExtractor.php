<?php

namespace App\Services\Music;

use App\Support\PublicDiskMedia;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Materialize a short audio clip for music recognition.
 *
 * Given a Post's media_url (public disk MP4, remote MP4, or data URI), download
 * or copy the source video to a temp file and use ffmpeg to extract a mono 16 kHz
 * WAV / MP3 slice. AudD needs raw audio bytes; AcoustID chromaprint needs a
 * file path for fpcalc. Callers own tempfile cleanup via {@see cleanup()}.
 */
class AudioClipExtractor
{
    /**
     * @return array{path: string, format: string, seconds: int, mean_dbfs: float|null, sha256: string|null}|null
     */
    public function extractFromMediaUrl(string $mediaUrl, int $seconds = 12): ?array
    {
        $seconds = max(4, min(30, $seconds));

        $sourcePath = $this->materializeSource($mediaUrl);

        if ($sourcePath === null) {
            return null;
        }

        try {
            return $this->extractFromLocalFile($sourcePath, $seconds);
        } finally {
            if ($this->isOwnedTempSource($sourcePath, $mediaUrl)) {
                @unlink($sourcePath);
            }
        }
    }

    /**
     * @return array{path: string, format: string, seconds: int, mean_dbfs: float|null, sha256: string|null}|null
     */
    public function extractFromLocalFile(string $sourcePath, int $seconds = 12): ?array
    {
        if (! is_file($sourcePath)) {
            return null;
        }

        $ffmpeg = (string) config('snitch.music_recognition.ffmpeg_binary', 'ffmpeg');

        $output = tempnam(sys_get_temp_dir(), 'snitch-clip-');
        if ($output === false) {
            throw new RuntimeException('Unable to allocate temp file for audio clip extraction.');
        }
        $target = $output.'.mp3';
        @unlink($output);

        // Mono 22.05kHz 96k MP3 is small (~150KB / 12s) and stays well under AudD's
        // upload limits while remaining recognisable for their fingerprint index.
        $result = Process::timeout(60)->run([
            $ffmpeg,
            '-y',
            '-i',
            $sourcePath,
            '-vn',
            '-ac',
            '1',
            '-ar',
            '22050',
            '-t',
            (string) $seconds,
            '-b:a',
            '96k',
            '-f',
            'mp3',
            $target,
        ]);

        if (! $result->successful() || ! is_file($target) || filesize($target) === 0) {
            @unlink($target);

            $stderr = strtolower($result->errorOutput().$result->output());
            if ($this->looksLikeMissingBinary($result->exitCode(), $stderr)) {
                throw new RuntimeException('ffmpeg is required for music recognition audio extraction.');
            }

            return null;
        }

        return [
            'path' => $target,
            'format' => 'mp3',
            'seconds' => $seconds,
            'mean_dbfs' => $this->meanVolumeDbfs($ffmpeg, $target),
            'sha256' => $this->hashFile($target),
        ];
    }

    public function cleanup(?string $path): void
    {
        if (is_string($path) && $path !== '' && is_file($path)) {
            @unlink($path);
        }
    }

    private function materializeSource(string $mediaUrl): ?string
    {
        if ($mediaUrl === '') {
            return null;
        }

        if (str_starts_with($mediaUrl, 'data:')) {
            return $this->writeDataUri($mediaUrl);
        }

        $relative = PublicDiskMedia::relativePathFromUrl($mediaUrl);
        if ($relative !== null && PublicDiskMedia::existsOnPublicDisk($mediaUrl)) {
            $absolute = Storage::disk('public')->path($relative);

            return is_file($absolute) ? $absolute : null;
        }

        if (! str_starts_with($mediaUrl, 'http')) {
            return null;
        }

        try {
            $response = Http::timeout(60)
                ->withHeaders(['User-Agent' => 'SnitchMusicRecognizer/1.0'])
                ->get($mediaUrl);
        } catch (\Throwable) {
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $body = $response->body();
        if ($body === '') {
            return null;
        }

        return $this->writeToTemp($body, 'mp4');
    }

    private function writeDataUri(string $mediaUrl): ?string
    {
        if (! preg_match('/^data:([^;]+);base64,(.*)$/s', $mediaUrl, $matches)) {
            return null;
        }

        $binary = base64_decode($matches[2], true);

        if (! is_string($binary) || $binary === '') {
            return null;
        }

        $extension = match (strtolower($matches[1])) {
            'video/webm' => 'webm',
            'video/quicktime' => 'mov',
            default => 'mp4',
        };

        return $this->writeToTemp($binary, $extension);
    }

    private function writeToTemp(string $bytes, string $extension): ?string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'snitch-src-');

        if ($tmp === false) {
            return null;
        }

        $target = $tmp.'.'.$extension;
        @unlink($tmp);

        if (@file_put_contents($target, $bytes) === false) {
            return null;
        }

        return $target;
    }

    private function isOwnedTempSource(string $sourcePath, string $mediaUrl): bool
    {
        if (str_starts_with($mediaUrl, 'data:')) {
            return true;
        }

        return ! str_starts_with($sourcePath, Storage::disk('public')->path(''));
    }

    private function meanVolumeDbfs(string $ffmpeg, string $target): ?float
    {
        $result = Process::timeout(30)->run([
            $ffmpeg,
            '-hide_banner',
            '-nostats',
            '-i',
            $target,
            '-af',
            'volumedetect',
            '-vn',
            '-sn',
            '-dn',
            '-f',
            'null',
            '-',
        ]);

        $output = $result->errorOutput().$result->output();

        if (preg_match('/mean_volume:\s*(-?\d+(?:\.\d+)?)\s*dB/', $output, $matches) === 1) {
            return (float) $matches[1];
        }

        return null;
    }

    private function hashFile(string $path): ?string
    {
        $hash = @hash_file('sha256', $path);

        return is_string($hash) && $hash !== '' ? $hash : null;
    }

    private function looksLikeMissingBinary(?int $exitCode, string $stderr): bool
    {
        if (in_array($exitCode, [126, 127], true)) {
            return true;
        }

        return str_contains($stderr, 'not found')
            || str_contains($stderr, 'no such file');
    }
}
