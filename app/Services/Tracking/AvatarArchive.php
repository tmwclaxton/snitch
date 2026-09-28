<?php

namespace App\Services\Tracking;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

class AvatarArchive
{
    private const MAX_BYTES = 5_000_000;

    public function isLocal(?string $url): bool
    {
        return $this->localRelative($url) !== null;
    }

    public function localFileExists(?string $url): bool
    {
        $relative = $this->localRelative($url);

        return $relative !== null && Storage::disk('public')->exists($relative);
    }

    /**
     * Download a remote avatar onto the public disk. Does not delete older
     * avatar files (Cloudflare caches /storage/* for 4h, so new hashes get
     * new URLs).
     */
    public function store(int $socialAccountId, string $remoteUrl): ?string
    {
        if ($socialAccountId < 1 || trim($remoteUrl) === '') {
            return null;
        }

        if ($this->isLocal($remoteUrl)) {
            return $this->normalizeLocalUrl($remoteUrl);
        }

        try {
            $timeout = app()->runningUnitTests() ? 2 : 15;
            $connect = app()->runningUnitTests() ? 1 : 5;
            $response = Http::timeout($timeout)
                ->connectTimeout($connect)
                ->withHeaders([
                    'User-Agent' => 'Mozilla/5.0',
                    'Accept' => 'image/avif,image/webp,image/apng,image/*,*/*;q=0.8',
                ])
                ->get($remoteUrl);
        } catch (Throwable $e) {
            Log::warning('Avatar download failed', [
                'social_account_id' => $socialAccountId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        if (! $response->successful()) {
            Log::warning('Avatar download rejected', [
                'social_account_id' => $socialAccountId,
                'status' => $response->status(),
            ]);

            return null;
        }

        $extension = $this->extension((string) $response->header('Content-Type'));

        if ($extension === null) {
            return null;
        }

        $body = $response->body();

        if ($body === '' || strlen($body) > self::MAX_BYTES) {
            return null;
        }

        $hash = substr(sha1($body), 0, 10);
        $relative = "avatars/{$socialAccountId}-{$hash}.{$extension}";
        Storage::disk('public')->put($relative, $body);

        return '/storage/'.$relative;
    }

    public function normalizeLocalUrl(string $url): string
    {
        $relative = $this->localRelative($url);

        return $relative !== null ? '/storage/'.$relative : $url;
    }

    private function localRelative(?string $url): ?string
    {
        if (! is_string($url) || trim($url) === '') {
            return null;
        }

        $path = parse_url(trim($url), PHP_URL_PATH);

        if (! is_string($path) || ! str_starts_with($path, '/storage/avatars/')) {
            return null;
        }

        $name = basename($path);

        if (preg_match('/^\d+-[a-f0-9]{10}\.(jpg|png|webp|gif)$/', $name) !== 1) {
            return null;
        }

        return 'avatars/'.$name;
    }

    private function extension(string $contentType): ?string
    {
        $type = strtolower(trim(strtok($contentType, ';') ?: ''));

        return match ($type) {
            'image/jpeg', 'image/jpg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
            default => null,
        };
    }
}
