<?php

namespace App\Services\Tracking;

use App\Enums\Platform;
use App\Models\Post;
use App\Support\PostCover;
use Illuminate\Support\Facades\Http;
use Throwable;

class PostCoverHydrator
{
    public function __construct(private PostCoverArchive $archive = new PostCoverArchive) {}

    public function discover(Post $post, bool $fetchRemote = false): ?string
    {
        $url = PostCover::resolve($post);

        if ($url === null && $fetchRemote) {
            $url = $this->fetchRemote($post);
        }

        return $url;
    }

    /**
     * @param  array<string, mixed>|null  $mapped
     */
    public function persist(Post $post, bool $fetchRemote = false, ?array $mapped = null): ?string
    {
        $stored = $this->stored($post);

        if ($this->archive->isDurable($stored)) {
            return $stored;
        }

        $source = $mapped !== null ? $this->shadow($post, $mapped) : $post;
        $remote = $this->discover($source);

        if ($this->archive->isStableRemote($remote)) {
            return $this->save($post, $remote);
        }

        $local = $this->archiveRemote($post, $remote);

        if ($local !== null) {
            return $this->save($post, $local);
        }

        if ($fetchRemote) {
            $fallback = $this->fetchRemote($source);

            if ($this->archive->isStableRemote($fallback)) {
                return $this->save($post, $fallback);
            }

            $local = $this->archiveRemote($post, $fallback);

            if ($local !== null) {
                return $this->save($post, $local);
            }
        }

        if ($stored === null && is_string($remote) && $remote !== '') {
            return $this->save($post, $remote);
        }

        return $stored;
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
            Platform::Instagram => $this->instagramMediaUrl($pageUrl),
            Platform::Facebook => $this->facebookOgImage($pageUrl),
            Platform::TikTok => $this->tikTokOembed($pageUrl),
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $mapped
     */
    private function shadow(Post $post, array $mapped): Post
    {
        $shadow = $post->replicate();
        $shadow->id = $post->id;
        $shadow->url = (string) ($mapped['url'] ?? $post->url);
        $shadow->media_url = isset($mapped['media_url']) ? (string) $mapped['media_url'] : $post->media_url;

        $payload = $mapped['raw_payload'] ?? null;
        $shadow->raw_payload = is_array($payload) ? $payload : $post->raw_payload;

        return $shadow;
    }

    private function archiveRemote(Post $post, ?string $remote): ?string
    {
        if (! is_string($remote) || $remote === '' || $post->id === null) {
            return null;
        }

        return $this->archive->store((int) $post->id, $remote);
    }

    private function save(Post $post, ?string $url): ?string
    {
        if (! is_string($url) || $url === '') {
            return null;
        }

        $stored = $this->stored($post);

        if ($stored !== $url) {
            $post->forceFill(['cover_url' => $url])->save();
        }

        return $url;
    }

    private function stored(Post $post): ?string
    {
        $stored = $post->getRawOriginal('cover_url');

        if (! is_string($stored)) {
            return null;
        }

        $stored = trim($stored);

        return $stored === '' ? null : $stored;
    }

    private function instagramMediaUrl(string $pageUrl): ?string
    {
        $path = parse_url($pageUrl, PHP_URL_PATH);

        if (! is_string($path) || preg_match('#/(?:reel|reels|p|tv)/[A-Za-z0-9_-]+#i', $path) !== 1) {
            return null;
        }

        return 'https://www.instagram.com'.rtrim($path, '/').'/media/?size=l';
    }

    private function facebookOgImage(string $pageUrl): ?string
    {
        try {
            $response = Http::timeout(12)
                ->connectTimeout(4)
                ->withHeaders([
                    'User-Agent' => 'Mozilla/5.0',
                    'Accept' => 'text/html',
                ])
                ->get($pageUrl);
        } catch (Throwable) {
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $html = $response->body();

        if (preg_match('/property="og:image" content="([^"]+)"/i', $html, $matches) !== 1
            && preg_match('/content="([^"]+)" property="og:image"/i', $html, $matches) !== 1) {
            return null;
        }

        $image = trim(html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5));

        if (! str_starts_with($image, 'https://') && ! str_starts_with($image, 'http://')) {
            return null;
        }

        return $image;
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
