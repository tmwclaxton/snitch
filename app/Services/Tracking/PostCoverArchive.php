<?php

namespace App\Services\Tracking;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Throwable;

class PostCoverArchive
{
    public function isDurable(?string $url): bool
    {
        $relative = $this->localRelative($url);

        if ($relative !== null) {
            return Storage::disk('public')->exists($relative);
        }

        return $this->isStableRemote($url);
    }

    public function isStableRemote(?string $url): bool
    {
        $host = $this->host($url);

        return $host !== null && str_ends_with($host, 'ytimg.com');
    }

    public function store(int $postId, string $remoteUrl): ?string
    {
        if ($postId < 1 || $this->isStableRemote($remoteUrl)) {
            return null;
        }

        try {
            $response = Http::timeout(15)
                ->connectTimeout(5)
                ->withHeaders([
                    'User-Agent' => 'Mozilla/5.0',
                    'Accept' => 'image/avif,image/webp,image/apng,image/*,*/*;q=0.8',
                ])
                ->get($remoteUrl);
        } catch (Throwable) {
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $extension = $this->extension((string) $response->header('Content-Type'));

        if ($extension === null) {
            return null;
        }

        $body = $response->body();

        if ($body === '' || strlen($body) > 8_000_000) {
            return null;
        }

        foreach (['jpg', 'png', 'webp', 'gif'] as $candidate) {
            if ($candidate !== $extension) {
                Storage::disk('public')->delete("post-covers/{$postId}.{$candidate}");
            }
        }

        $relative = "post-covers/{$postId}.{$extension}";
        Storage::disk('public')->put($relative, $body);

        return '/storage/'.$relative;
    }

    private function localRelative(?string $url): ?string
    {
        if (! is_string($url) || trim($url) === '') {
            return null;
        }

        $path = parse_url(trim($url), PHP_URL_PATH);

        if (! is_string($path) || ! str_starts_with($path, '/storage/post-covers/')) {
            return null;
        }

        $name = basename($path);

        if (preg_match('/^\d+\.(jpg|png|webp|gif)$/', $name) !== 1) {
            return null;
        }

        return 'post-covers/'.$name;
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

    private function host(?string $url): ?string
    {
        if (! is_string($url) || trim($url) === '') {
            return null;
        }

        $host = parse_url(trim($url), PHP_URL_HOST);

        if (! is_string($host) || $host === '') {
            return null;
        }

        return strtolower($host);
    }
}
