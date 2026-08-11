<?php

namespace App\Services\Music;

use App\Models\Post;
use App\Services\Analysis\PlatformMusicExtractor;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Song identification pipeline for a Post's media.
 *
 * Order of preference (short-circuits at the first authoritative result):
 *
 * 1. Platform music metadata via {@see PlatformMusicExtractor} - already stored
 *    on the scrape payload for TikTok / Instagram. Model / provider guesses
 *    never override this.
 * 2. AcoustID + Chromaprint (fpcalc) - free provider, ideal for commercial
 *    tracks with a fingerprint match.
 * 3. AudD /recognize - short-clip fallback that reaches trend audio and
 *    original masters AcoustID misses.
 *
 * Results are cached by audio SHA-256 so re-analysis and duplicate clips do
 * not spend provider credits twice.
 */
class MusicRecognitionService
{
    public function __construct(
        private PlatformMusicExtractor $platformExtractor,
        private AudioClipExtractor $clipExtractor,
        private ChromaprintFingerprinter $fingerprinter,
        private AcoustIdClient $acoustId,
        private AudDClient $audd,
    ) {}

    public function enabled(): bool
    {
        return (bool) config('snitch.music_recognition.enabled', true);
    }

    /**
     * Provider outcome persisted onto post_analyses.music.
     *
     * @return array{
     *     title: string|null,
     *     artist: string|null,
     *     album?: string|null,
     *     isrc?: string|null,
     *     is_original_audio?: bool|null,
     *     source: string,
     *     provider?: string,
     *     confidence?: float,
     *     recording_id?: string|null,
     *     media_hash?: string|null,
     *     identified_at?: string
     * }|null
     */
    public function recognize(Post $post): ?array
    {
        $platform = $this->platformExtractor->fromPost($post);

        if ($platform !== null) {
            return $this->platformResult($platform);
        }

        if (! $this->enabled()) {
            return null;
        }

        $mediaUrl = (string) $post->media_url;
        if ($mediaUrl === '') {
            return null;
        }

        $clipSeconds = (int) config('snitch.music_recognition.clip_seconds', 12);

        $clip = null;

        try {
            $clip = $this->clipExtractor->extractFromMediaUrl($mediaUrl, $clipSeconds);
        } catch (Throwable $e) {
            Log::info('MusicRecognitionService clip extraction failed.', [
                'post_id' => $post->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        if ($clip === null) {
            return null;
        }

        try {
            if ($this->clipLooksSilent($clip)) {
                Log::info('MusicRecognitionService skipping silent clip.', [
                    'post_id' => $post->id,
                    'mean_dbfs' => $clip['mean_dbfs'],
                ]);

                return null;
            }

            $mediaHash = $clip['sha256'];
            $cached = $mediaHash !== null ? $this->cache($mediaHash) : null;

            if ($cached !== null) {
                return $this->finaliseProviderResult($cached, $mediaHash);
            }

            $providerResult = $this->runAcoustId($clip)
                ?? $this->runAudD($clip);

            if ($providerResult === null) {
                return null;
            }

            if ($mediaHash !== null) {
                $this->cache($mediaHash, $providerResult);
            }

            return $this->finaliseProviderResult($providerResult, $mediaHash);
        } finally {
            $this->clipExtractor->cleanup($clip['path'] ?? null);
        }
    }

    /**
     * @param  array{title: string|null, artist: string|null, is_original_audio: bool|null, platform_id: string|null, source: 'platform'}  $platform
     * @return array{
     *     title: string|null,
     *     artist: string|null,
     *     is_original_audio: bool|null,
     *     platform_id: string|null,
     *     source: 'platform'
     * }
     */
    private function platformResult(array $platform): array
    {
        return array_filter($platform, static fn ($value) => $value !== null);
    }

    /**
     * @param  array{path: string, format: string, seconds: int, mean_dbfs: float|null, sha256: string|null}  $clip
     * @return array{
     *     provider: 'acoustid',
     *     title: string|null,
     *     artist: string|null,
     *     confidence: float,
     *     recording_id: string|null,
     *     raw: array<string, mixed>|null
     * }|null
     */
    private function runAcoustId(array $clip): ?array
    {
        if (! $this->acoustId->isConfigured()) {
            return null;
        }

        $fingerprint = $this->fingerprinter->fingerprint($clip['path']);

        if ($fingerprint === null) {
            return null;
        }

        $result = $this->acoustId->lookup($fingerprint['fingerprint'], $fingerprint['duration']);

        if ($result === null) {
            return null;
        }

        if ($result['confidence'] < $this->minConfidence()) {
            return null;
        }

        return $result;
    }

    /**
     * @param  array{path: string, format: string, seconds: int, mean_dbfs: float|null, sha256: string|null}  $clip
     * @return array{
     *     provider: 'audd',
     *     title: string|null,
     *     artist: string|null,
     *     album: string|null,
     *     isrc: string|null,
     *     confidence: float,
     *     raw: array<string, mixed>|null
     * }|null
     */
    private function runAudD(array $clip): ?array
    {
        if (! $this->audd->isConfigured()) {
            return null;
        }

        $result = $this->audd->recognizeFile($clip['path']);

        if ($result === null) {
            return null;
        }

        if ($result['confidence'] < $this->minConfidence()) {
            return null;
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $providerResult
     * @return array{
     *     title: string|null,
     *     artist: string|null,
     *     album?: string|null,
     *     isrc?: string|null,
     *     source: string,
     *     provider: string,
     *     confidence: float,
     *     recording_id?: string|null,
     *     media_hash?: string|null,
     *     identified_at: string,
     *     is_original_audio: false
     * }
     */
    private function finaliseProviderResult(array $providerResult, ?string $mediaHash): array
    {
        $payload = [
            'title' => $providerResult['title'] ?? null,
            'artist' => $providerResult['artist'] ?? null,
            'source' => (string) ($providerResult['provider'] ?? 'unknown'),
            'provider' => (string) ($providerResult['provider'] ?? 'unknown'),
            'confidence' => (float) ($providerResult['confidence'] ?? 0.0),
            'is_original_audio' => false,
            'identified_at' => now()->toIso8601String(),
        ];

        if (isset($providerResult['album']) && $providerResult['album'] !== null) {
            $payload['album'] = $providerResult['album'];
        }

        if (isset($providerResult['isrc']) && $providerResult['isrc'] !== null) {
            $payload['isrc'] = $providerResult['isrc'];
        }

        if (isset($providerResult['recording_id']) && $providerResult['recording_id'] !== null) {
            $payload['recording_id'] = $providerResult['recording_id'];
        }

        if ($mediaHash !== null) {
            $payload['media_hash'] = $mediaHash;
        }

        return $payload;
    }

    /**
     * @param  array{path: string, format: string, seconds: int, mean_dbfs: float|null, sha256: string|null}  $clip
     */
    private function clipLooksSilent(array $clip): bool
    {
        $mean = $clip['mean_dbfs'];

        if ($mean === null) {
            return false;
        }

        $threshold = (float) config('snitch.music_recognition.silence_dbfs', -45.0);

        return $mean <= $threshold;
    }

    private function minConfidence(): float
    {
        return (float) config('snitch.music_recognition.min_confidence', 0.55);
    }

    /**
     * @param  array<string, mixed>|null  $value
     * @return array<string, mixed>|null
     */
    private function cache(string $mediaHash, ?array $value = null): ?array
    {
        $key = 'music_recognition:hash:'.$mediaHash;
        $ttl = max(60, (int) config('snitch.music_recognition.cache_ttl_seconds', 60 * 60 * 24 * 30));

        if ($value === null) {
            $cached = Cache::get($key);

            return is_array($cached) ? $cached : null;
        }

        Cache::put($key, $value, $ttl);

        return $value;
    }
}
