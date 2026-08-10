<?php

namespace App\Services\Scraping;

use App\Services\TikHub\TikHubClient;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Resolve downloadable YouTube Shorts / watch MP4 URLs via TikHub player data.
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
        if ($posts === [] || ! $this->configured()) {
            return array_values(array_filter(
                $posts,
                fn (array $post): bool => $this->isDownloadableMediaUrl($post['media_url'] ?? null),
            ));
        }

        $hydrated = [];

        foreach ($posts as $post) {
            if ($this->isDownloadableMediaUrl($post['media_url'] ?? null)) {
                $hydrated[] = $post;

                continue;
            }

            $downloadUrl = $this->resolveDownloadUrl(
                url: (string) ($post['url'] ?? ''),
                videoId: isset($post['external_id']) ? (string) $post['external_id'] : null,
            );

            if ($downloadUrl === null) {
                continue;
            }

            $post['media_url'] = $downloadUrl;
            $hydrated[] = $post;
        }

        return $hydrated;
    }

    public function resolveDownloadUrl(?string $url = null, ?string $videoId = null): ?string
    {
        if (! $this->configured()) {
            return null;
        }

        $id = $this->extractVideoId($videoId, $url);

        if ($id === null) {
            return null;
        }

        try {
            $payload = $this->client->get($this->endpoint(), [
                'video_id' => $id,
            ], 'youtube');
        } catch (Throwable $e) {
            Log::info('YoutubeMediaHydrator TikHub resolve failed', [
                'video_id' => $id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        return $this->pickDownloadUrl($payload);
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

            $url = $format['url'] ?? null;

            if (! is_string($url) || $url === '' || ! str_starts_with($url, 'http')) {
                continue;
            }

            $mime = strtolower((string) ($format['mimeType'] ?? ''));

            if ($mime !== '' && ! str_contains($mime, 'video/')) {
                continue;
            }

            $height = (int) ($format['height'] ?? 0);

            if ($height >= $bestMuxedHeight) {
                $bestMuxedHeight = $height;
                $bestMuxed = $url;
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

            $url = $format['url'] ?? null;
            $mime = strtolower((string) ($format['mimeType'] ?? ''));

            if (! is_string($url) || $url === '' || ! str_contains($mime, 'video/mp4')) {
                continue;
            }

            $height = (int) ($format['height'] ?? 0);

            if ($height >= $bestAdaptiveHeight) {
                $bestAdaptiveHeight = $height;
                $bestAdaptive = $url;
            }
        }

        return $bestAdaptive;
    }

    private function endpoint(): string
    {
        return (string) config(
            'snitch.tikhub.endpoints.youtube.video_info',
            '/api/v1/youtube/web/get_video_info_v2',
        );
    }
}
