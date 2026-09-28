<?php

namespace App\Services\Tracking;

use App\Enums\Platform;
use App\Models\Post;
use App\Services\TikHub\TikHubClient;
use App\Support\InstagramPostId;
use App\Support\PostCover;
use App\Support\PublicDiskMedia;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Throwable;

class PostCoverHydrator
{
    public function __construct(
        private PostCoverArchive $archive = new PostCoverArchive,
        private ?TikHubClient $tikhub = null,
    ) {}

    public function discover(Post $post, bool $fetchRemote = false): ?string
    {
        $url = PostCover::resolve($post);

        if ($url === null && $fetchRemote) {
            $url = $this->fetchRemote($post);
        }

        return $url;
    }

    public function needsMirroring(Post $post): bool
    {
        $stored = $this->stored($post);

        if ($stored === null || $stored === '') {
            return true;
        }

        if ($this->archive->isDurable($stored)) {
            return false;
        }

        return str_starts_with($stored, 'http://') || str_starts_with($stored, 'https://');
    }

    /**
     * Ensure a durable cover is on disk when possible. Safe to call from
     * Explore / Feed for posts that never went through tracker sync.
     */
    public function ensureMirrored(Post $post, bool $fetchRemote = true): ?string
    {
        if (! $this->needsMirroring($post)) {
            return $this->stored($post);
        }

        // Viewer requests must not block the suite / CI on outbound CDN downloads.
        if (app()->runningUnitTests()) {
            return $this->stored($post);
        }

        try {
            return $this->persist($post, fetchRemote: $fetchRemote);
        } catch (Throwable) {
            return $this->stored($post);
        }
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
            return $this->save($post, $remote, $remote);
        }

        $local = $this->archiveRemote($post, $remote);

        if ($local !== null) {
            return $this->save($post, $local, is_string($remote) ? $remote : null);
        }

        if ($fetchRemote) {
            $fallback = $this->fetchRemote($source);

            if ($this->archive->isStableRemote($fallback)) {
                return $this->save($post, $fallback, $fallback);
            }

            $local = $this->archiveRemote($post, $fallback);

            if ($local !== null) {
                return $this->save($post, $local, is_string($fallback) ? $fallback : null);
            }

            if (is_string($fallback) && $fallback !== '') {
                $remote = $fallback;
            }
        }

        // Never persist an expiring CDN URL as cover_url - only keep the source
        // for a later --fetch retry. Grids must see null / local / ytimg only.
        if (is_string($remote) && $remote !== '' && $this->sourceUrl($post) !== $remote) {
            $post->forceFill(['cover_source_url' => $remote])->save();
        }

        if ($stored !== null && PostCover::isDisplayableStill($stored) && ! $this->archive->isDurable($stored)
            && (str_starts_with($stored, 'http://') || str_starts_with($stored, 'https://'))) {
            $post->forceFill(['cover_url' => null])->save();
            $stored = null;
        }

        $poster = $this->posterFromMedia($post);

        if ($poster !== null) {
            return $this->save($post, $poster, is_string($remote) ? $remote : $this->sourceUrl($post));
        }

        if (is_string($stored) && ! PostCover::isDisplayableStill($stored)) {
            $post->forceFill(['cover_url' => null])->save();

            return null;
        }

        return $this->archive->isDurable($stored) ? $stored : null;
    }

    /**
     * LinkedIn streams have no thumbnail and no file extension, so the
     * video URL was stored as the cover and grids showed an empty frame.
     * Grab one JPEG from the media file instead.
     */
    private function posterFromMedia(Post $post): ?string
    {
        if ($post->id === null) {
            return null;
        }

        $media = trim((string) $post->media_url);

        if ($media === '') {
            return null;
        }

        $relative = PublicDiskMedia::relativePathFromUrl($media);

        if ($relative !== null) {
            if (! Storage::disk('public')->exists($relative)) {
                return null;
            }

            $input = Storage::disk('public')->path($relative);
        } elseif (str_starts_with($media, 'http://') || str_starts_with($media, 'https://')) {
            $input = $media;
        } else {
            return null;
        }

        $temp = tempnam(sys_get_temp_dir(), 'snitch-poster-');

        if ($temp === false) {
            return null;
        }

        $jpg = $temp.'.jpg';
        @unlink($temp);

        $ffmpeg = (string) config('snitch.video_analysis.ffmpeg_binary', 'ffmpeg');
        $command = [$ffmpeg, '-y', '-ss', '0.4', '-i', $input, '-frames:v', '1', '-q:v', '3', $jpg];

        if (str_starts_with($input, 'http://') || str_starts_with($input, 'https://')) {
            array_splice($command, 2, 0, ['-user_agent', 'Mozilla/5.0']);
        }

        try {
            $result = Process::timeout(25)->run($command);
        } catch (Throwable) {
            @unlink($jpg);

            return null;
        }

        if (! $result->successful() || ! is_file($jpg)) {
            @unlink($jpg);

            return null;
        }

        $bytes = file_get_contents($jpg);
        @unlink($jpg);

        if (! is_string($bytes) || ! str_starts_with($bytes, "\xFF\xD8")) {
            return null;
        }

        Storage::disk('public')->put('post-covers/'.$post->id.'.jpg', $bytes);

        return '/storage/post-covers/'.$post->id.'.jpg';
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
            Platform::Instagram => $this->instagramFreshStill($post, $pageUrl),
            Platform::Facebook => $this->facebookOgImage($pageUrl),
            Platform::TikTok => $this->tikTokFreshStill($post, $pageUrl),
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

    private function save(Post $post, ?string $url, ?string $sourceUrl = null): ?string
    {
        if (! is_string($url) || $url === '') {
            return null;
        }

        $fill = [];
        $stored = $this->stored($post);

        if ($stored !== $url) {
            $fill['cover_url'] = $url;
        }

        if (is_string($sourceUrl) && $sourceUrl !== '' && $this->sourceUrl($post) !== $sourceUrl) {
            $fill['cover_source_url'] = $sourceUrl;
        } elseif (
            (str_starts_with($url, 'http://') || str_starts_with($url, 'https://'))
            && ! $this->archive->isStableRemote($url)
            && $this->sourceUrl($post) !== $url
        ) {
            $fill['cover_source_url'] = $url;
        }

        if ($fill !== []) {
            $post->forceFill($fill)->save();
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

    private function sourceUrl(Post $post): ?string
    {
        $source = $post->getAttribute('cover_source_url');

        if (! is_string($source)) {
            return null;
        }

        $source = trim($source);

        return $source === '' ? null : $source;
    }

    private function instagramFreshStill(Post $post, string $pageUrl): ?string
    {
        $fromTikHub = $this->instagramTikHubStill($post, $pageUrl);

        if ($fromTikHub !== null) {
            return $fromTikHub;
        }

        return $this->instagramMediaUrl($pageUrl);
    }

    private function instagramTikHubStill(Post $post, string $pageUrl): ?string
    {
        $client = $this->tikhubClient();

        if ($client === null || ! $client->configured()) {
            return null;
        }

        $code = InstagramPostId::fromUrl($pageUrl)
            ?? InstagramPostId::fromPayload(is_array($post->raw_payload) ? $post->raw_payload : [])
            ?? (is_string($post->external_id) ? $post->external_id : null);

        if (! is_string($code) || $code === '' || preg_match('/^[A-Za-z0-9_-]+$/', $code) !== 1) {
            return null;
        }

        $path = (string) config('snitch.tikhub.endpoints.instagram.post_info_by_code', '');

        if ($path === '') {
            return null;
        }

        try {
            $payload = $client->get($path, ['code' => $code], 'instagram');
        } catch (Throwable) {
            return null;
        }

        $item = data_get($payload, 'data.items.0');

        if (! is_array($item)) {
            $item = data_get($payload, 'data.data');
        }

        if (! is_array($item)) {
            $item = data_get($payload, 'data');
        }

        if (! is_array($item)) {
            return null;
        }

        $shadow = $post->replicate();
        $shadow->id = $post->id;
        $shadow->raw_payload = $item;
        $shadow->media_url = null;
        $shadow->url = $pageUrl;

        return PostCover::resolve($shadow);
    }

    private function tikTokFreshStill(Post $post, string $pageUrl): ?string
    {
        $fromTikHub = $this->tikTokTikHubStill($post);

        if ($fromTikHub !== null) {
            return $fromTikHub;
        }

        return $this->tikTokOembed($pageUrl);
    }

    private function tikTokTikHubStill(Post $post): ?string
    {
        $client = $this->tikhubClient();

        if ($client === null || ! $client->configured()) {
            return null;
        }

        $awemeId = is_string($post->external_id) ? trim($post->external_id) : '';

        if ($awemeId === '' || ! ctype_digit($awemeId)) {
            return null;
        }

        $path = (string) config('snitch.tikhub.endpoints.tiktok.one_video', '');

        if ($path === '') {
            return null;
        }

        try {
            $payload = $client->get($path, ['aweme_id' => $awemeId], 'tiktok');
        } catch (Throwable) {
            return null;
        }

        $item = data_get($payload, 'data.aweme_detail');

        if (! is_array($item)) {
            $item = data_get($payload, 'aweme_detail');
        }

        if (! is_array($item)) {
            $item = data_get($payload, 'data');
        }

        if (! is_array($item)) {
            return null;
        }

        $shadow = $post->replicate();
        $shadow->id = $post->id;
        $shadow->raw_payload = $item;
        $shadow->media_url = null;

        return PostCover::resolve($shadow);
    }

    private function tikhubClient(): ?TikHubClient
    {
        if ($this->tikhub instanceof TikHubClient) {
            return $this->tikhub;
        }

        try {
            return app(TikHubClient::class);
        } catch (Throwable) {
            return null;
        }
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
