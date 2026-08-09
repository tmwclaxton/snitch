<?php

namespace App\Services\Apify\Adapters;

use App\Enums\Platform;
use App\Enums\PostType;
use Carbon\CarbonImmutable;

class TikTokAdapter extends AbstractPlatformAdapter
{
    public function platform(): Platform
    {
        return Platform::TikTok;
    }

    protected function actorId(): string
    {
        return (string) config('snitch.apify.actors.tiktok');
    }

    protected function actorInput(string $handle, int $limit, ?CarbonImmutable $since = null): array
    {
        return [
            'profiles' => [$handle],
            'resultsPerPage' => $limit,
            'oldestPostDateUnified' => $this->dateFilterValue($since),
            // Metadata-first: paid video download happens in hydrateMediaUrls for new posts only.
            'shouldDownloadVideos' => false,
        ];
    }

    /**
     * Native TikTok user search for influencer discovery seeds.
     *
     * @return array{actorId: string, input: array<string, mixed>}
     */
    public function searchUsersActorJob(string $query, int $limit): array
    {
        return [
            'actorId' => $this->actorId(),
            'input' => $this->searchUsersActorInput($query, $limit),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function searchUsersActorInput(string $query, int $limit): array
    {
        return [
            'searchQueries' => [$query],
            'searchSection' => '/user',
            'maxProfilesPerQuery' => max(1, $limit),
            'resultsPerPage' => 1,
            'shouldDownloadVideos' => false,
            'scrapeAdditionalAuthorMeta' => true,
        ];
    }

    protected function mapProfile(array $item, string $handle): ?array
    {
        $author = is_array($item['authorMeta'] ?? null) ? $item['authorMeta'] : (is_array($item['author'] ?? null) ? $item['author'] : null);

        if ($author === null && ! isset($item['authorName'])) {
            return null;
        }

        $resolved = (string) ($author['name'] ?? $author['uniqueId'] ?? $item['authorName'] ?? $handle);

        return [
            'platform' => $this->platform(),
            'handle' => ltrim($resolved, '@'),
            'url' => $this->profileUrl(ltrim($resolved, '@')),
            'external_id' => isset($author['id']) ? (string) $author['id'] : null,
            'avatar' => $author['avatar'] ?? $item['authorAvatar'] ?? null,
            'display_name' => $author['nickName'] ?? $author['nickname'] ?? $resolved,
        ];
    }

    protected function mapPost(array $item, string $handle): ?array
    {
        $url = (string) ($item['webVideoUrl'] ?? $item['url'] ?? '');

        if ($url === '') {
            return null;
        }

        if (! empty($item['isSlideshow'])) {
            return null;
        }

        $mediaUrl = $this->firstVideoUrl(
            $item['videoUrl'] ?? null,
            $item['mediaUrls'] ?? null,
            $item['videoMeta']['downloadAddr'] ?? null,
        );

        $music = is_array($item['musicMeta'] ?? null) ? $item['musicMeta'] : (is_array($item['music'] ?? null) ? $item['music'] : null);

        return [
            'external_id' => isset($item['id']) ? (string) $item['id'] : null,
            'url' => $url,
            'posted_at' => $this->normalizeDate($item['createTime'] ?? $item['createTimeISO'] ?? null),
            'type' => PostType::Reel->value,
            'caption' => isset($item['text']) ? (string) $item['text'] : (isset($item['desc']) ? (string) $item['desc'] : null),
            // Null until hydrateMediaUrls downloads the file (metadata-only list).
            'media_url' => $mediaUrl,
            'metrics' => $this->metrics(
                $item['playCount'] ?? $item['videoMeta']['playCount'] ?? 0,
                $item['diggCount'] ?? $item['likes'] ?? 0,
                $item['commentCount'] ?? 0,
                $item['shareCount'] ?? 0,
            ),
            'raw_payload' => array_merge($item, is_array($music) ? ['normalized_music' => $music] : []),
        ];
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
    public function hydrateMediaUrls(array $posts): array
    {
        if ($posts === []) {
            return [];
        }

        $needsDownload = [];

        foreach ($posts as $index => $post) {
            if (blank($post['media_url'] ?? null) && filled($post['url'] ?? null)) {
                $needsDownload[$index] = $post;
            }
        }

        if ($needsDownload === []) {
            return array_values(array_filter(
                $posts,
                static fn (array $post): bool => filled($post['media_url'] ?? null),
            ));
        }

        $postUrls = array_values(array_unique(array_map(
            static fn (array $post): string => (string) $post['url'],
            $needsDownload,
        )));

        $items = $this->client->runActor($this->actorId(), [
            'postURLs' => $postUrls,
            'resultsPerPage' => max(1, count($postUrls)),
            'shouldDownloadVideos' => true,
        ]);

        $mediaByUrl = [];
        $mediaByExternalId = [];

        foreach ($items as $item) {
            $mapped = $this->mapPost($item, 'hydrate');

            if ($mapped === null || blank($mapped['media_url'])) {
                continue;
            }

            $watchUrl = (string) ($mapped['url'] ?? '');
            if ($watchUrl !== '') {
                $mediaByUrl[$this->normalizeWatchUrl($watchUrl)] = $mapped['media_url'];
            }

            if (filled($mapped['external_id'])) {
                $mediaByExternalId[(string) $mapped['external_id']] = $mapped['media_url'];
            }
        }

        $hydrated = [];

        foreach ($posts as $post) {
            if (filled($post['media_url'] ?? null)) {
                $hydrated[] = $post;

                continue;
            }

            $externalId = isset($post['external_id']) ? (string) $post['external_id'] : '';
            $watchKey = $this->normalizeWatchUrl((string) ($post['url'] ?? ''));
            $mediaUrl = $mediaByExternalId[$externalId] ?? $mediaByUrl[$watchKey] ?? null;

            if (blank($mediaUrl)) {
                continue;
            }

            $post['media_url'] = $mediaUrl;
            $hydrated[] = $post;
        }

        return $hydrated;
    }

    private function normalizeWatchUrl(string $url): string
    {
        $url = strtolower(trim($url));
        $url = strtok($url, '?') ?: $url;

        return rtrim($url, '/');
    }
}
