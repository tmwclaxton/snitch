<?php

namespace App\Services\Music;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * AudD /recognize wrapper. Accepts a short audio file (< ~1MB / 20s) and
 * returns a best-effort match. AudD does not return a numeric confidence, so we
 * derive one from the presence of an ISRC/Apple Music id (higher confidence) or
 * fall back to a floor value.
 */
class AudDClient
{
    public function isConfigured(): bool
    {
        return trim((string) config('snitch.music_recognition.audd.api_key')) !== '';
    }

    /**
     * @return array{
     *     provider: 'audd',
     *     title: string|null,
     *     artist: string|null,
     *     album: string|null,
     *     isrc: string|null,
     *     confidence: float,
     *     spotify_track_id: string|null,
     *     spotify_url: string|null,
     *     apple_music_id: string|null,
     *     apple_music_url: string|null,
     *     raw: array<string, mixed>|null
     * }|null
     */
    public function recognizeFile(string $audioPath): ?array
    {
        if (! $this->isConfigured() || ! is_file($audioPath) || filesize($audioPath) <= 0) {
            return null;
        }

        try {
            $response = $this->http()
                ->attach('file', file_get_contents($audioPath), basename($audioPath))
                ->post('/', [
                    'api_token' => (string) config('snitch.music_recognition.audd.api_key'),
                    'return' => 'apple_music,spotify',
                ]);
        } catch (\Throwable $e) {
            Log::info('AudDClient recognize failed.', ['error' => $e->getMessage()]);

            return null;
        }

        if (! $response->successful()) {
            Log::info('AudDClient non-2xx response.', [
                'status' => $response->status(),
                'body' => mb_substr((string) $response->body(), 0, 300),
            ]);

            return null;
        }

        $payload = $response->json();

        if (! is_array($payload) || ($payload['status'] ?? null) !== 'success') {
            return null;
        }

        $result = $payload['result'] ?? null;

        if (! is_array($result) || $result === []) {
            return null;
        }

        $title = $this->trimmedString($result['title'] ?? null);
        $artist = $this->trimmedString($result['artist'] ?? null);

        if ($title === null && $artist === null) {
            return null;
        }

        $album = $this->trimmedString($result['album'] ?? null);
        $isrc = $this->trimmedString($result['isrc'] ?? null)
            ?? $this->trimmedString(data_get($result, 'apple_music.isrc'))
            ?? $this->trimmedString(data_get($result, 'spotify.external_ids.isrc'));

        $spotifyId = $this->trimmedString(data_get($result, 'spotify.id'));
        $spotifyUrl = $this->trimmedString(data_get($result, 'spotify.external_urls.spotify'));

        if ($spotifyUrl === null && $spotifyId !== null) {
            $spotifyUrl = 'https://open.spotify.com/track/'.$spotifyId;
        }

        $appleId = $this->trimmedString(data_get($result, 'apple_music.id'));
        $appleUrl = $this->trimmedString(data_get($result, 'apple_music.url'));

        return [
            'provider' => 'audd',
            'title' => $title,
            'artist' => $artist,
            'album' => $album,
            'isrc' => $isrc,
            'confidence' => $this->deriveConfidence($result, $isrc),
            'spotify_track_id' => $spotifyId,
            'spotify_url' => $spotifyUrl,
            'apple_music_id' => $appleId,
            'apple_music_url' => $appleUrl,
            'raw' => [
                'song_link' => $this->trimmedString($result['song_link'] ?? null),
                'label' => $this->trimmedString($result['label'] ?? null),
                'release_date' => $this->trimmedString($result['release_date'] ?? null),
                'apple_music_id' => $appleId,
                'spotify_id' => $spotifyId,
            ],
        ];
    }

    /**
     * AudD does not surface a numeric score; treat a matched track with an
     * ISRC / streaming id as a strong hit, and a bare title / artist match as
     * merely plausible. Callers can compare against min_confidence.
     *
     * @param  array<string, mixed>  $result
     */
    private function deriveConfidence(array $result, ?string $isrc): float
    {
        $confidence = 0.6;

        if ($isrc !== null) {
            $confidence = 0.9;
        } elseif ($this->trimmedString(data_get($result, 'apple_music.id')) !== null
            || $this->trimmedString(data_get($result, 'spotify.id')) !== null) {
            $confidence = 0.85;
        } elseif ($this->trimmedString($result['album'] ?? null) !== null) {
            $confidence = 0.7;
        }

        return round($confidence, 2);
    }

    private function trimmedString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    protected function http(): PendingRequest
    {
        return Http::baseUrl((string) config('snitch.music_recognition.audd.base_url'))
            ->acceptJson()
            ->timeout((int) config('snitch.music_recognition.audd.timeout', 30))
            ->retry(2, 500, fn (\Throwable $exception): bool => $exception instanceof ConnectionException);
    }
}
