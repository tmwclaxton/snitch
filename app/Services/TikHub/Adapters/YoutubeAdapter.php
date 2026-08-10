<?php

namespace App\Services\TikHub\Adapters;

use App\Enums\Platform;
use App\Enums\PostType;
use Carbon\CarbonImmutable;

class YoutubeAdapter extends AbstractTikHubAdapter
{
    /** @var array<string, string> */
    private array $channelIdCache = [];

    public function platform(): Platform
    {
        return Platform::Youtube;
    }

    public function resolveProfile(string $handleOrUrl): array
    {
        $handle = $this->normalizeHandle($handleOrUrl);
        $channelId = $this->resolveChannelId($handle);

        return $this->profileFromItems([[
            'channel_id' => $channelId,
            'channelUsername' => $handle,
            'channelName' => $handle,
            'handle' => $handle,
        ]], $handle);
    }

    public function listRecentPosts(string $handleOrUrl, int $limit = 12, ?CarbonImmutable $since = null): array
    {
        $handle = $this->normalizeHandle($handleOrUrl);
        $channelId = $this->resolveChannelId($handle) ?? $handle;

        try {
            $payload = $this->client->get($this->endpoint('channel_shorts'), [
                'channel_id' => $channelId,
            ], 'youtube');
        } catch (\Throwable) {
            $payload = $this->client->get($this->endpoint('channel_videos'), [
                'channel_id' => $channelId,
                'channel_name' => $handle,
                'url' => $this->profileUrl($handle),
            ], 'youtube');
        }

        $items = $this->extractList($payload, ['videos', 'data.videos', 'items', 'data.items', 'shorts']);

        return $this->postsFromItems($items, $handle, $limit, $since);
    }

    private function resolveChannelId(string $handle): ?string
    {
        if (str_starts_with($handle, 'UC') && strlen($handle) >= 20) {
            return $handle;
        }

        if (isset($this->channelIdCache[$handle])) {
            return $this->channelIdCache[$handle];
        }

        $payload = $this->client->get($this->endpoint('channel_info'), [
            'channel_name' => $handle,
            'url' => $this->profileUrl($handle),
        ], 'youtube');

        $data = $payload['data'] ?? $payload;
        $channelId = is_array($data)
            ? ($data['channel_id'] ?? $data['channelId'] ?? $data['id'] ?? null)
            : null;

        if (is_string($channelId) && $channelId !== '') {
            $this->channelIdCache[$handle] = $channelId;

            return $channelId;
        }

        return null;
    }

    /**
     * @return list<array{name: string, platform: string, handle: string, followers: int|null, seed: string}>
     */
    public function searchChannels(string $query, int $limit): array
    {
        $payload = $this->client->get($this->endpoint('search'), [
            'search_query' => $query,
            'query' => $query,
        ], 'youtube');

        $items = $this->extractList($payload, ['videos', 'data.videos', 'items', 'data.items']);
        $out = [];
        $seen = [];

        foreach ($items as $item) {
            $handle = ltrim((string) ($item['channelUsername'] ?? $item['channel_handle'] ?? $item['channelHandle'] ?? ''), '@');
            $channelId = (string) ($item['channelId'] ?? $item['channel_id'] ?? '');

            if ($handle === '' && $channelId === '') {
                continue;
            }

            $key = $handle !== '' ? $handle : $channelId;

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $out[] = [
                'name' => (string) ($item['channelName'] ?? $item['channel_title'] ?? ($handle !== '' ? $handle : $channelId)),
                'platform' => Platform::Youtube->value,
                'handle' => $handle !== '' ? $handle : $channelId,
                'followers' => isset($item['subscriberCount']) ? (int) $item['subscriberCount'] : null,
                'seed' => 'tikhub-search',
            ];

            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }

    protected function mapProfile(array $item, string $handle): ?array
    {
        $channelHandle = ltrim((string) ($item['channelUsername'] ?? $item['channel_handle'] ?? $item['handle'] ?? $handle), '@');
        $channelId = $item['channelId'] ?? $item['channel_id'] ?? $item['id'] ?? null;

        if ($channelHandle === '' && $channelId === null) {
            return null;
        }

        $resolved = $channelHandle !== '' ? $channelHandle : $handle;

        return [
            'platform' => $this->platform(),
            'handle' => $resolved,
            'url' => $this->profileUrl($resolved),
            'external_id' => $channelId !== null ? (string) $channelId : null,
            'avatar' => $item['channelAvatarUrl'] ?? $item['avatar'] ?? $item['thumbnail'] ?? null,
            'display_name' => (string) ($item['channelName'] ?? $item['title'] ?? $resolved),
        ];
    }

    protected function mapPost(array $item, string $handle): ?array
    {
        if (! $this->isShort($item)) {
            return null;
        }

        $id = isset($item['video_id'])
            ? (string) $item['video_id']
            : (isset($item['videoId']) ? (string) $item['videoId'] : (isset($item['id']) ? (string) $item['id'] : null));
        $url = (string) ($item['url'] ?? $item['shortsUrl'] ?? '');

        if ($url === '' && $id !== null) {
            $url = "https://www.youtube.com/shorts/{$id}";
        }

        if ($url === '') {
            return null;
        }

        $mediaUrl = $this->firstVideoUrl(
            $item['videoUrl'] ?? null,
            $item['downloadUrl'] ?? null,
            $item['mediaUrl'] ?? null,
        );

        if ($mediaUrl === null) {
            $mediaUrl = $url;
        }

        return [
            'external_id' => $id,
            'url' => $url,
            'posted_at' => $this->normalizeDate(
                $item['publishedAt']
                ?? $item['publish_date']
                ?? $item['published_time']
                ?? $item['date']
                ?? null,
            ),
            'type' => PostType::Reel->value,
            'caption' => isset($item['title']) ? (string) $item['title'] : null,
            'media_url' => $mediaUrl,
            'metrics' => $this->metrics(
                $item['viewCount'] ?? $item['view_count'] ?? $item['number_of_views'] ?? $item['views'] ?? 0,
                $item['likeCount'] ?? $item['likes'] ?? 0,
                $item['commentCount'] ?? $item['comments'] ?? 0,
                0,
            ),
            'raw_payload' => $item,
        ];
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function isShort(array $item): bool
    {
        $url = strtolower((string) ($item['url'] ?? $item['shortsUrl'] ?? ''));
        $type = strtolower((string) ($item['type'] ?? $item['videoType'] ?? ''));

        if (str_contains($url, '/shorts/') || str_contains($type, 'short')) {
            return true;
        }

        if (isset($item['isShort']) && (bool) $item['isShort']) {
            return true;
        }

        $seconds = null;
        $duration = $item['duration'] ?? $item['lengthSeconds'] ?? null;

        if (is_numeric($duration)) {
            $seconds = (int) $duration;
        }

        return $seconds !== null && $seconds > 0 && $seconds <= 60;
    }
}
