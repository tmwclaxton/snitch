<?php

namespace App\Services\Music;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Thin wrapper for AcoustID's /v2/lookup endpoint.
 *
 * AcoustID takes a chromaprint fingerprint + duration and returns a ranked list
 * of MusicBrainz recording matches with a score in [0, 1]. Free tier is
 * generous but requests are still shaped conservatively (single meta=recordings
 * lookup, no releases fan-out).
 */
class AcoustIdClient
{
    public function isConfigured(): bool
    {
        return trim((string) config('snitch.music_recognition.acoustid.api_key')) !== '';
    }

    /**
     * @return array{
     *     provider: 'acoustid',
     *     title: string|null,
     *     artist: string|null,
     *     confidence: float,
     *     recording_id: string|null,
     *     raw: array<string, mixed>|null
     * }|null
     */
    public function lookup(string $fingerprint, int $durationSeconds): ?array
    {
        if (! $this->isConfigured() || $fingerprint === '' || $durationSeconds <= 0) {
            return null;
        }

        try {
            $response = $this->http()
                ->asForm()
                ->post('/lookup', [
                    'client' => (string) config('snitch.music_recognition.acoustid.api_key'),
                    'format' => 'json',
                    'meta' => 'recordings',
                    'duration' => $durationSeconds,
                    'fingerprint' => $fingerprint,
                ]);
        } catch (\Throwable $e) {
            Log::info('AcoustIdClient lookup failed.', ['error' => $e->getMessage()]);

            return null;
        }

        if (! $response->successful()) {
            Log::info('AcoustIdClient non-2xx response.', [
                'status' => $response->status(),
                'body' => mb_substr((string) $response->body(), 0, 300),
            ]);

            return null;
        }

        $payload = $response->json();

        if (! is_array($payload) || ($payload['status'] ?? null) !== 'ok') {
            return null;
        }

        $results = $payload['results'] ?? [];

        if (! is_array($results) || $results === []) {
            return null;
        }

        return $this->pickBestResult($results);
    }

    /**
     * @param  list<array<string, mixed>>  $results
     * @return array{
     *     provider: 'acoustid',
     *     title: string|null,
     *     artist: string|null,
     *     confidence: float,
     *     recording_id: string|null,
     *     raw: array<string, mixed>|null
     * }|null
     */
    private function pickBestResult(array $results): ?array
    {
        // Sort by score desc so we always evaluate the strongest hit first.
        usort(
            $results,
            static fn (array $a, array $b): int => ((float) ($b['score'] ?? 0)) <=> ((float) ($a['score'] ?? 0)),
        );

        foreach ($results as $result) {
            if (! is_array($result)) {
                continue;
            }

            $score = (float) ($result['score'] ?? 0);
            $recordings = is_array($result['recordings'] ?? null) ? $result['recordings'] : [];

            if ($recordings === []) {
                continue;
            }

            $bestRecording = $this->pickRecording($recordings);

            if ($bestRecording === null) {
                continue;
            }

            return [
                'provider' => 'acoustid',
                'title' => $bestRecording['title'] ?? null,
                'artist' => $bestRecording['artist'] ?? null,
                'confidence' => round(max(0.0, min(1.0, $score)), 3),
                'recording_id' => $bestRecording['id'] ?? null,
                'raw' => [
                    'acoustid_id' => $result['id'] ?? null,
                    'recording_id' => $bestRecording['id'] ?? null,
                ],
            ];
        }

        return null;
    }

    /**
     * @param  list<mixed>  $recordings
     * @return array{id: string|null, title: string|null, artist: string|null}|null
     */
    private function pickRecording(array $recordings): ?array
    {
        foreach ($recordings as $recording) {
            if (! is_array($recording)) {
                continue;
            }

            $title = isset($recording['title']) && is_string($recording['title'])
                ? trim($recording['title'])
                : null;

            if ($title === null || $title === '') {
                continue;
            }

            $artists = is_array($recording['artists'] ?? null) ? $recording['artists'] : [];
            $artistNames = [];
            foreach ($artists as $artist) {
                if (is_array($artist) && isset($artist['name']) && is_string($artist['name'])) {
                    $name = trim($artist['name']);
                    if ($name !== '') {
                        $artistNames[] = $name;
                    }
                }
            }

            return [
                'id' => isset($recording['id']) && is_string($recording['id']) ? $recording['id'] : null,
                'title' => $title,
                'artist' => $artistNames === [] ? null : implode(', ', $artistNames),
            ];
        }

        return null;
    }

    protected function http(): PendingRequest
    {
        return Http::baseUrl((string) config('snitch.music_recognition.acoustid.base_url'))
            ->acceptJson()
            ->timeout((int) config('snitch.music_recognition.acoustid.timeout', 20))
            ->retry(2, 500, fn (\Throwable $exception): bool => $exception instanceof ConnectionException);
    }
}
