<?php

namespace App\Services\Analysis;

use App\Models\Post;

/**
 * Prefer platform music metadata from scrape payloads over model song guesses.
 *
 * TikTok / Instagram adapters stash a normalized blob on raw_payload.normalized_music.
 * NanoGPT vision models rarely identify commercial tracks; they also invent titles.
 * There is no app path for audio fingerprinting (NanoGPT MCP music tools generate
 * audio, they do not identify songs).
 */
class PlatformMusicExtractor
{
    /**
     * @return array{
     *     title: string|null,
     *     artist: string|null,
     *     is_original_audio: bool|null,
     *     platform_id: string|null,
     *     source: 'platform'
     * }|null
     */
    public function fromPost(Post $post): ?array
    {
        $payload = is_array($post->raw_payload) ? $post->raw_payload : [];

        $candidates = [
            $payload['normalized_music'] ?? null,
            $payload['musicMeta'] ?? null,
            $payload['music'] ?? null,
            $payload['musicInfo'] ?? null,
            $payload['audio'] ?? null,
            data_get($payload, 'clips_metadata.music_info'),
            data_get($payload, 'clips_metadata.original_sound_info'),
        ];

        foreach ($candidates as $candidate) {
            if (! is_array($candidate)) {
                continue;
            }

            $extracted = $this->fromArray($candidate);

            if ($extracted !== null) {
                return $extracted;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $music
     * @return array{
     *     title: string|null,
     *     artist: string|null,
     *     is_original_audio: bool|null,
     *     platform_id: string|null,
     *     source: 'platform'
     * }|null
     */
    public function fromArray(array $music): ?array
    {
        $title = $this->firstString($music, [
            'musicName',
            'song_name',
            'title',
            'music_name',
            'name',
            'audio_name',
        ]);
        $artist = $this->firstString($music, [
            'musicAuthor',
            'artist_name',
            'artist',
            'authorName',
            'author',
            'owner',
        ]);
        $platformId = $this->firstString($music, [
            'musicId',
            'audio_id',
            'id',
            'audio_asset_id',
        ]);

        $isOriginal = null;
        foreach (['musicOriginal', 'uses_original_audio', 'original', 'is_original'] as $key) {
            if (! array_key_exists($key, $music)) {
                continue;
            }

            $value = $music[$key];
            if (is_bool($value)) {
                $isOriginal = $value;
                break;
            }
            if (is_int($value) || is_float($value) || (is_string($value) && is_numeric($value))) {
                $isOriginal = (bool) $value;
                break;
            }
        }

        if ($title === null && $artist === null && $platformId === null && $isOriginal === null) {
            return null;
        }

        if ($isOriginal === null && is_string($title)) {
            $lower = strtolower($title);
            if (str_contains($lower, 'original sound') || str_contains($lower, 'original audio')) {
                $isOriginal = true;
            }
        }

        return [
            'title' => $title,
            'artist' => $artist,
            'is_original_audio' => $isOriginal,
            'platform_id' => $platformId,
            'source' => 'platform',
        ];
    }

    /**
     * Merge platform metadata over model guesses for persistence.
     *
     * @return array{
     *     title?: string,
     *     artist?: string,
     *     is_original_audio?: bool,
     *     platform_id?: string,
     *     source?: string
     * }
     */
    public function mergeForAnalysis(?array $platform, ?string $modelTitle, ?string $modelArtist, bool $modelIsOriginal): array
    {
        if ($platform !== null) {
            $merged = array_filter([
                'title' => $platform['title'],
                'artist' => $platform['artist'],
                'is_original_audio' => $platform['is_original_audio'] ?? $modelIsOriginal,
                'platform_id' => $platform['platform_id'],
                'source' => 'platform',
            ], static fn ($value) => $value !== null);

            return $merged;
        }

        return array_filter([
            'title' => $modelTitle,
            'artist' => $modelArtist,
            'is_original_audio' => $modelIsOriginal,
            'source' => ($modelTitle !== null || $modelArtist !== null) ? 'model' : null,
        ], static fn ($value) => $value !== null);
    }

    /**
     * @param  array<string, mixed>  $music
     * @param  list<string>  $keys
     */
    private function firstString(array $music, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (! isset($music[$key])) {
                continue;
            }

            $value = trim((string) $music[$key]);

            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }
}
