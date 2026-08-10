<?php

namespace App\Services\Scraping;

use App\Services\TikHub\TikHubClient;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Resolve YouTube Shorts / watch streams via TikHub, then store a public MP4
 * NanoGPT can fetch (googlevideo URLs are IP-bound to the resolver).
 */
class YoutubeMediaHydrator
{
    public function __construct(private TikHubClient $client) {}

    public function configured(): bool
    {
        return $this->client->configured();
    }

    /**
     * @param  list<array{
     *     external_id: string|null,
     *     url: string,
     *     posted_at: string|null,
     *     type: string,
     *     caption: string|null,
     *     media_url: string|null,
     *     metrics: array<string, mixed>,
     *     raw_payload: array<string, mixed>
     * }>  $posts
     * @return list<array{
     *     external_id: string|null,
     *     url: string,
     *     posted_at: string|null,
     *     type: string,
     *     caption: string|null,
     *     media_url: string|null,
     *     metrics: array<string, mixed>,
     *     raw_payload: array<string, mixed>
     * }>
     */
    public function hydratePosts(array $posts): array
    {
        if ($posts === []) {
            return [];
        }

        $hydrated = [];

        foreach ($posts as $post) {
            $mediaUrl = $post['media_url'] ?? null;

            if ($this->isPubliclyFetchableMediaUrl($mediaUrl)) {
                $hydrated[] = $post;

                continue;
            }

            $downloadUrl = $this->resolveDownloadUrl(
                url: (string) ($post['url'] ?? ''),
                videoId: isset($post['external_id']) ? (string) $post['external_id'] : null,
                existingMediaUrl: is_string($mediaUrl) ? $mediaUrl : null,
            );

            if ($downloadUrl === null) {
                continue;
            }

            $post['media_url'] = $downloadUrl;
            $hydrated[] = $post;
        }

        return $hydrated;
    }

    public function resolveDownloadUrl(
        ?string $url = null,
        ?string $videoId = null,
        ?string $existingMediaUrl = null,
    ): ?string {
        $id = $this->extractVideoId($videoId, $url);

        if ($id === null) {
            return null;
        }

        $existingPath = "youtube-media/{$id}.mp4";

        if (Storage::disk('public')->exists($existingPath)) {
            return $this->absolutePublicUrl($existingPath);
        }

        $streamUrl = null;

        if ($this->isDownloadableMediaUrl($existingMediaUrl) && $this->isYoutubeCdnUrl((string) $existingMediaUrl)) {
            $streamUrl = $existingMediaUrl;
        }

        if ($streamUrl === null) {
            $streamUrl = $this->resolveStreamUrl($id);
        }

        if ($streamUrl === null) {
            return null;
        }

        return $this->persistPublicCopy($streamUrl, $id);
    }

    public function needsHydration(?string $mediaUrl): bool
    {
        if (! $this->isDownloadableMediaUrl($mediaUrl)) {
            return true;
        }

        return $this->isYoutubeCdnUrl((string) $mediaUrl);
    }

    public function isDownloadableMediaUrl(mixed $mediaUrl): bool
    {
        if (! is_string($mediaUrl) || $mediaUrl === '' || ! str_starts_with($mediaUrl, 'http')) {
            return false;
        }

        $lower = strtolower($mediaUrl);

        if (str_contains($lower, 'youtube.com/') || str_contains($lower, 'youtu.be/')) {
            return preg_match('/\.(mp4|webm|m3u8)(\?|$)/i', $mediaUrl) === 1;
        }

        return true;
    }

    public function isYoutubeCdnUrl(string $mediaUrl): bool
    {
        $host = strtolower((string) parse_url($mediaUrl, PHP_URL_HOST));

        return str_contains($host, 'googlevideo.com')
            || str_contains($host, 'youtube.com')
            || str_contains($host, 'youtu.be');
    }

    public function isPubliclyFetchableMediaUrl(mixed $mediaUrl): bool
    {
        if (! $this->isDownloadableMediaUrl($mediaUrl)) {
            return false;
        }

        return ! $this->isYoutubeCdnUrl((string) $mediaUrl);
    }

    public function extractVideoId(?string $videoId, ?string $url): ?string
    {
        if (is_string($videoId) && preg_match('/^[A-Za-z0-9_-]{6,}$/', $videoId) === 1) {
            return $videoId;
        }

        if (! is_string($url) || $url === '') {
            return null;
        }

        if (preg_match('#(?:youtube\.com/shorts/|youtube\.com/watch\?v=|youtu\.be/)([A-Za-z0-9_-]{6,})#i', $url, $matches) === 1) {
            return $matches[1];
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function pickDownloadUrl(array $payload): ?string
    {
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : $payload;
        $streaming = is_array($data['streamingData'] ?? null) ? $data['streamingData'] : [];
        $formats = is_array($streaming['formats'] ?? null) ? $streaming['formats'] : [];
        $adaptive = is_array($streaming['adaptiveFormats'] ?? null) ? $streaming['adaptiveFormats'] : [];

        $bestMuxed = null;
        $bestMuxedHeight = -1;

        foreach ($formats as $format) {
            if (! is_array($format)) {
                continue;
            }

            $candidate = $format['url'] ?? null;

            if (! is_string($candidate) || $candidate === '' || ! str_starts_with($candidate, 'http')) {
                continue;
            }

            $mime = strtolower((string) ($format['mimeType'] ?? ''));

            if ($mime !== '' && ! str_contains($mime, 'video/')) {
                continue;
            }

            $height = (int) ($format['height'] ?? 0);

            if ($height >= $bestMuxedHeight) {
                $bestMuxedHeight = $height;
                $bestMuxed = $candidate;
            }
        }

        if ($bestMuxed !== null) {
            return $bestMuxed;
        }

        $bestAdaptive = null;
        $bestAdaptiveHeight = -1;

        foreach ($adaptive as $format) {
            if (! is_array($format)) {
                continue;
            }

            $candidate = $format['url'] ?? null;
            $mime = strtolower((string) ($format['mimeType'] ?? ''));

            if (! is_string($candidate) || $candidate === '' || ! str_contains($mime, 'video/mp4')) {
                continue;
            }

            $height = (int) ($format['height'] ?? 0);

            if ($height >= $bestAdaptiveHeight) {
                $bestAdaptiveHeight = $height;
                $bestAdaptive = $candidate;
            }
        }

        return $bestAdaptive;
    }

    private function resolveStreamUrl(string $videoId): ?string
    {
        if (! $this->configured()) {
            return null;
        }

        try {
            $payload = $this->client->get($this->endpoint(), [
                'video_id' => $videoId,
            ], 'youtube');
        } catch (Throwable $e) {
            Log::info('YoutubeMediaHydrator TikHub resolve failed', [
                'video_id' => $videoId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        return $this->pickDownloadUrl($payload);
    }

    private function persistPublicCopy(string $streamUrl, string $videoId): ?string
    {
        $path = "youtube-media/{$videoId}.mp4";

        try {
            $response = Http::timeout(120)
                ->withHeaders(['User-Agent' => 'SnitchYoutubeHydrator/1.0'])
                ->get($streamUrl);

            if (! $response->successful() || blank($response->body())) {
                Log::info('YoutubeMediaHydrator download failed', [
                    'video_id' => $videoId,
                    'status' => $response->status(),
                ]);

                return null;
            }

            Storage::disk('public')->put($path, $response->body());
        } catch (Throwable $e) {
            Log::info('YoutubeMediaHydrator persist failed', [
                'video_id' => $videoId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        return $this->absolutePublicUrl($path);
    }

    private function absolutePublicUrl(string $path): string
    {
        $url = Storage::disk('public')->url($path);

        if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
            return $url;
        }

        return rtrim((string) config('app.url'), '/').'/'.ltrim($url, '/');
    }

    private function endpoint(): string
    {
        return (string) config(
            'snitch.tikhub.endpoints.youtube.video_info',
            '/api/v1/youtube/web/get_video_info_v2',
        );
    }
}
