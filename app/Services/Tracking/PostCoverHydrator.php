<?php

namespace App\Services\Tracking;

use App\Enums\Platform;
use App\Models\Post;
use App\Support\PostCover;
use Illuminate\Support\Facades\Http;
use Throwable;

class PostCoverHydrator
{
    public function discover(Post $post, bool $fetchRemote = false): ?string
    {
        $url = PostCover::resolve($post);

        if ($url === null && $fetchRemote) {
            $url = $this->fetchRemote($post);
        }

        return $url;
    }

    public function persist(Post $post, bool $fetchRemote = false): ?string
    {
        $url = $this->discover($post, $fetchRemote);

        if ($url === null) {
            return null;
        }

        $stored = $post->getRawOriginal('cover_url');

        if ($stored !== $url) {
            $post->forceFill(['cover_url' => $url])->save();
        }

        return $url;
    }

    public function fetchRemote(Post $post): ?string
    {
        $pageUrl = is_string($post->url) ? trim($post->url) : '';

        if ($pageUrl === '') {
            return null;
        }

        $platform = $post->platform instanceof Platform
            ? $post->platform
            : Platform::tryFrom((string) $post->platform);

        return match ($platform) {
            Platform::TikTok => $this->tikTokOembed($pageUrl),
            default => null,
        };
    }

    private function tikTokOembed(string $pageUrl): ?string
    {
        try {
            $response = Http::timeout(8)
                ->connectTimeout(3)
                ->acceptJson()
                ->get('https://www.tiktok.com/oembed', [
                    'url' => $pageUrl,
                ]);
        } catch (Throwable) {
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $thumbnail = $response->json('thumbnail_url');

        if (! is_string($thumbnail) || trim($thumbnail) === '') {
            return null;
        }

        $thumbnail = trim($thumbnail);

        if (! str_starts_with($thumbnail, 'https://') && ! str_starts_with($thumbnail, 'http://')) {
            return null;
        }

        return $thumbnail;
    }
}
