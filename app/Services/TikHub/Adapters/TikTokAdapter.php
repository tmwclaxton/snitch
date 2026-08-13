<?php

namespace App\Services\TikHub\Adapters;

use App\Enums\Platform;
use App\Enums\PostType;
use Carbon\CarbonImmutable;

class TikTokAdapter extends AbstractTikHubAdapter
{
    public function platform(): Platform
    {
        return Platform::TikTok;
    }

    public function resolveProfile(string $handleOrUrl): array
    {
        $handle = $this->normalizeHandle($handleOrUrl);
        $payload = $this->client->get($this->endpoint('user_profile'), [
            'uniqueId' => $handle,
            'unique_id' => $handle,
        ], 'tiktok');

        $item = $this->extractObject($payload, ['userInfo', 'user', 'data.userInfo', 'data.user']);

        return $this->profileFromItems([$item !== [] ? $item : $payload], $handle);
    }

    public function listRecentPosts(string $handleOrUrl, int $limit = 12, ?CarbonImmutable $since = null): array
    {
        $handle = $this->normalizeHandle($handleOrUrl);

        $query = [
            'unique_id' => $handle,
            'uniqueId' => $handle,
            'count' => max($limit, (int) ceil($limit * 1.25)),
            'max_cursor' => 0,
        ];

        // Prefer posts by unique_id alone; secUid lookup would double profile COGS.
        $payload = $this->client->get($this->endpoint('user_posts'), $query, 'tiktok');
        $items = $this->extractList($payload, ['aweme_list', 'data.aweme_list', 'itemList', 'data.itemList']);

        return $this->postsFromItems($items, $handle, $limit, $since);
    }

    public function hydrateMediaUrls(array $posts): array
    {
        if ($posts === []) {
            return [];
        }

        $hydrated = [];

        foreach ($posts as $post) {
            if (filled($post['media_url'] ?? null)) {
                $hydrated[] = $post;

                continue;
            }

            $awemeId = (string) ($post['external_id'] ?? '');

            if ($awemeId === '') {
                continue;
            }

            try {
                $payload = $this->client->get($this->endpoint('one_video'), [
                    'aweme_id' => $awemeId,
                ], 'tiktok');
                $item = $this->extractObject($payload, ['aweme_detail', 'data.aweme_detail', 'aweme']);
                $mapped = $this->mapPost($item !== [] ? $item : $payload, 'hydrate');

                if ($mapped === null || blank($mapped['media_url'] ?? null)) {
                    continue;
                }

                $post['media_url'] = $mapped['media_url'];
                $hydrated[] = $post;
            } catch (\Throwable) {
                continue;
            }
        }

        return $hydrated;
    }

    /**
     * @return list<array{name: string, platform: string, handle: string, followers: int|null, seed: string}>
     */
    public function searchUsers(string $query, int $limit): array
    {
        $payload = $this->client->get($this->endpoint('search_users'), [
            'keyword' => $query,
            'count' => max(1, $limit),
            'offset' => 0,
        ], 'tiktok');

        $items = $this->extractList($payload, ['user_list', 'data.user_list', 'users', 'data.users']);
        $out = [];

        foreach ($items as $item) {
            $user = is_array($item['user_info'] ?? null) ? $item['user_info'] : (is_array($item['user'] ?? null) ? $item['user'] : $item);
            $handle = ltrim((string) ($user['unique_id'] ?? $user['uniqueId'] ?? $user['nickname'] ?? ''), '@');

            if ($handle === '') {
                continue;
            }

            $out[] = [
                'name' => (string) ($user['nickname'] ?? $user['nick_name'] ?? $handle),
                'platform' => Platform::TikTok->value,
                'handle' => $handle,
                'followers' => isset($user['follower_count']) ? (int) $user['follower_count'] : (isset($user['followers']) ? (int) $user['followers'] : null),
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
        $user = is_array($item['user'] ?? null) ? $item['user'] : (is_array($item['userInfo'] ?? null) ? $item['userInfo'] : $item);
        $stats = is_array($item['stats'] ?? null) ? $item['stats'] : (is_array($user['stats'] ?? null) ? $user['stats'] : []);

        $resolved = ltrim((string) ($user['uniqueId'] ?? $user['unique_id'] ?? $user['nickname'] ?? $handle), '@');

        if ($resolved === '') {
            return null;
        }

        $externalId = $user['id'] ?? $user['uid'] ?? $user['user_id'] ?? null;
        $followers = $stats['followerCount'] ?? $stats['follower_count'] ?? $user['follower_count'] ?? $user['followers'] ?? null;

        $profile = [
            'platform' => $this->platform(),
            'handle' => $resolved,
            'url' => $this->profileUrl($resolved),
            'external_id' => $externalId !== null ? (string) $externalId : null,
            'avatar' => $user['avatarLarger'] ?? $user['avatarThumb'] ?? $user['avatar_larger']['url_list'][0] ?? null,
            'display_name' => $user['nickname'] ?? $user['nick_name'] ?? $resolved,
        ];

        if (is_numeric($followers) && (int) $followers >= 0) {
            $profile['followers'] = (int) $followers;
        }

        return $profile;
    }

    protected function mapPost(array $item, string $handle): ?array
    {
        $aweme = is_array($item['aweme'] ?? null) ? $item['aweme'] : $item;
        $id = isset($aweme['aweme_id']) ? (string) $aweme['aweme_id'] : (isset($aweme['id']) ? (string) $aweme['id'] : null);
        $author = is_array($aweme['author'] ?? null) ? $aweme['author'] : [];
        $uniqueId = ltrim((string) ($author['unique_id'] ?? $author['uniqueId'] ?? $handle), '@');
        $url = (string) ($aweme['share_url'] ?? '');

        if ($url === '' && $id !== null && $uniqueId !== '') {
            $url = "https://www.tiktok.com/@{$uniqueId}/video/{$id}";
        }

        if ($url === '') {
            return null;
        }

        if (! empty($aweme['is_slides']) || ! empty($aweme['image_post_info'])) {
            return null;
        }

        $mediaUrl = $this->firstVideoUrl(
            data_get($aweme, 'video.play_addr.url_list.0'),
            data_get($aweme, 'video.download_addr.url_list.0'),
            data_get($aweme, 'video.playAddr'),
            data_get($aweme, 'video.downloadAddr'),
            $aweme['video_url'] ?? null,
        );

        return [
            'external_id' => $id,
            'url' => $url,
            'posted_at' => $this->normalizeDate($aweme['create_time'] ?? $aweme['createTime'] ?? null),
            'type' => PostType::Reel->value,
            'caption' => isset($aweme['desc']) ? (string) $aweme['desc'] : (isset($aweme['description']) ? (string) $aweme['description'] : null),
            'media_url' => $mediaUrl,
            'metrics' => $this->metrics(
                data_get($aweme, 'statistics.play_count') ?? data_get($aweme, 'stats.playCount') ?? 0,
                data_get($aweme, 'statistics.digg_count') ?? data_get($aweme, 'stats.diggCount') ?? 0,
                data_get($aweme, 'statistics.comment_count') ?? data_get($aweme, 'stats.commentCount') ?? 0,
                data_get($aweme, 'statistics.share_count') ?? data_get($aweme, 'stats.shareCount') ?? 0,
            ),
            'raw_payload' => $item,
        ];
    }
}
