<?php

namespace App\Services\Music;

use App\Services\Firecrawl\FirecrawlClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Resolves a Spotify track URL / id for a recognised song without paying Spotify
 * API fees. Order of preference, cheapest first:
 *
 * 1. Direct Spotify id / url returned by AudD (free - already fetched).
 * 2. Firecrawl web search "title artist site:open.spotify.com" (cached, skipped
 *    for original / platform audio and blank titles).
 *
 * Results are cached by ISRC when available and by a title+artist fingerprint
 * otherwise so re-analysis and duplicate matches never re-hit Firecrawl.
 */
class SpotifyLinkResolver
{
    public function __construct(private FirecrawlClient $firecrawl) {}

    /**
     * @param  array{
     *     title?: string|null,
     *     artist?: string|null,
     *     isrc?: string|null,
     *     spotify_track_id?: string|null,
     *     spotify_url?: string|null,
     *     is_original_audio?: bool|null
     * }  $music
     * @return array{
     *     spotify_track_id: string,
     *     spotify_url: string,
     *     spotify_embed_url: string,
     *     resolved_via: 'audd'|'firecrawl'
     * }|null
     */
    public function resolve(array $music): ?array
    {
        $direct = $this->fromDirectFields($music);

        if ($direct !== null) {
            return $direct + ['resolved_via' => 'audd'];
        }

        if (! $this->firecrawlEnabled()) {
            return null;
        }

        if (($music['is_original_audio'] ?? false) === true) {
            return null;
        }

        $title = $this->cleanTitle($music['title'] ?? null);
        $artist = $this->cleanArtist($music['artist'] ?? null);

        if ($title === null) {
            return null;
        }

        if (! $this->titleLooksSpecific($title)) {
            return null;
        }

        $isrc = $this->cleanIsrc($music['isrc'] ?? null);
        $cacheKey = $isrc !== null
            ? 'spotify_resolver:isrc:'.$isrc
            : 'spotify_resolver:query:'.hash('sha256', strtolower($title.'|'.((string) $artist)));

        $ttl = (int) config('snitch.music_recognition.spotify_resolver.cache_ttl_seconds', 60 * 60 * 24 * 30);

        $cached = Cache::get($cacheKey);

        if (is_array($cached) && isset($cached['spotify_track_id'])) {
            return $cached + ['resolved_via' => 'firecrawl'];
        }

        // Explicit MISS sentinel so we do not thrash Firecrawl on repeat unresolved tracks.
        if ($cached === 'miss') {
            return null;
        }

        $resolved = $this->searchViaFirecrawl($title, $artist, $isrc);

        if ($resolved === null) {
            Cache::put($cacheKey, 'miss', max(60, min($ttl, 60 * 60 * 24 * 7)));

            return null;
        }

        Cache::put($cacheKey, $resolved, $ttl);

        return $resolved + ['resolved_via' => 'firecrawl'];
    }

    /**
     * @param  array{spotify_track_id?: string|null, spotify_url?: string|null}  $music
     * @return array{spotify_track_id: string, spotify_url: string, spotify_embed_url: string}|null
     */
    private function fromDirectFields(array $music): ?array
    {
        $trackId = $this->cleanTrackId($music['spotify_track_id'] ?? null);

        if ($trackId === null && isset($music['spotify_url']) && is_string($music['spotify_url'])) {
            $trackId = $this->extractTrackIdFromUrl($music['spotify_url']);
        }

        if ($trackId === null) {
            return null;
        }

        return [
            'spotify_track_id' => $trackId,
            'spotify_url' => 'https://open.spotify.com/track/'.$trackId,
            'spotify_embed_url' => 'https://open.spotify.com/embed/track/'.$trackId,
        ];
    }

    /**
     * @return array{spotify_track_id: string, spotify_url: string, spotify_embed_url: string}|null
     */
    private function searchViaFirecrawl(string $title, ?string $artist, ?string $isrc): ?array
    {
        $limit = max(1, (int) config('snitch.music_recognition.spotify_resolver.search_limit', 5));

        $queries = array_values(array_unique(array_filter([
            $isrc !== null ? $isrc.' site:open.spotify.com' : null,
            trim(($artist ?? '').' '.$title).' site:open.spotify.com/track',
            trim(($artist ?? '').' '.$title).' spotify',
        ])));

        if ($queries === []) {
            return null;
        }

        try {
            $hits = $this->firecrawl->searchMany($queries, ['limit' => $limit]);
        } catch (Throwable $e) {
            Log::info('SpotifyLinkResolver firecrawl search failed.', [
                'title' => $title,
                'artist' => $artist,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        foreach ($hits as $hit) {
            $trackId = $this->extractTrackIdFromUrl($hit['url'] ?? '');

            if ($trackId === null) {
                continue;
            }

            return [
                'spotify_track_id' => $trackId,
                'spotify_url' => 'https://open.spotify.com/track/'.$trackId,
                'spotify_embed_url' => 'https://open.spotify.com/embed/track/'.$trackId,
            ];
        }

        return null;
    }

    private function extractTrackIdFromUrl(string $url): ?string
    {
        $url = trim($url);

        if ($url === '') {
            return null;
        }

        // Matches open.spotify.com/track/{id} and open.spotify.com/{locale}/track/{id}.
        if (preg_match('!https?://open\.spotify\.com/(?:[a-z-]{2,10}/)?track/([A-Za-z0-9]{22})(?:[?/\#]|$)!', $url, $matches) === 1) {
            return $matches[1];
        }

        return null;
    }

    private function cleanTrackId(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return preg_match('/^[A-Za-z0-9]{22}$/', $value) === 1 ? $value : null;
    }

    private function cleanTitle(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private function cleanArtist(mixed $value): ?string
    {
        return $this->cleanTitle($value);
    }

    private function cleanIsrc(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = strtoupper(trim($value));

        return preg_match('/^[A-Z]{2}[A-Z0-9]{3}\d{7}$/', $value) === 1 ? $value : null;
    }

    /**
     * "Original sound" / "original audio" / bare handles are not useful queries.
     */
    private function titleLooksSpecific(string $title): bool
    {
        $lower = strtolower($title);

        if (str_contains($lower, 'original sound') || str_contains($lower, 'original audio')) {
            return false;
        }

        return mb_strlen($title) >= 2;
    }

    private function firecrawlEnabled(): bool
    {
        if (! (bool) config('snitch.music_recognition.spotify_resolver.enabled', true)) {
            return false;
        }

        return trim((string) config('snitch.firecrawl.api_key')) !== '';
    }
}
