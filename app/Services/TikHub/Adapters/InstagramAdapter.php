<?php

namespace App\Services\TikHub\Adapters;

use App\Enums\Platform;
use App\Enums\PostType;
use Carbon\CarbonImmutable;

class InstagramAdapter extends AbstractTikHubAdapter
{
    public function platform(): Platform
    {
        return Platform::Instagram;
    }

    public function resolveProfile(string $handleOrUrl): array
    {
        $handle = $this->normalizeHandle($handleOrUrl);
        $payload = $this->client->get($this->endpoint('user_info'), [
            'username' => $handle,
        ], 'instagram');

        $item = $this->extractObject($payload, ['data.data', 'data', 'user', 'data.user', 'user_info']);

        return $this->profileFromItems([$item !== [] ? $item : $payload], $handle);
    }

    public function listRecentPosts(string $handleOrUrl, int $limit = 12, ?CarbonImmutable $since = null): array
    {
        $handle = $this->normalizeHandle($handleOrUrl);
        $fetch = max($limit, (int) ceil($limit * 2.5));

        try {
            $payload = $this->client->get($this->endpoint('user_reels'), [
                'username' => $handle,
                'count' => $fetch,
            ], 'instagram');
        } catch (\Throwable) {
            $payload = $this->client->get($this->endpoint('user_posts'), [
                'username' => $handle,
                'count' => $fetch,
            ], 'instagram');
        }

        $items = $this->extractList($payload, [
            'items',
            'data.items',
            'reels',
            'data.reels',
            'medias',
            'data.medias',
        ]);

        return $this->postsFromItems($items, $handle, $limit, $since);
    }

    /**
     * @return list<array{name: string, platform: string, handle: string, followers: int|null, seed: string}>
     */
    public function searchUsers(string $query, int $limit): array
    {
        $payload = $this->client->get($this->endpoint('search_users'), [
            'keyword' => $query,
            'count' => max(1, $limit),
        ], 'instagram');

        $items = $this->extractList($payload, ['users', 'data.users', 'user_list', 'items']);
        $out = [];

        foreach ($items as $item) {
            $user = is_array($item['user'] ?? null) ? $item['user'] : $item;
            $handle = ltrim((string) ($user['username'] ?? $user['user_name'] ?? ''), '@');

            if ($handle === '') {
                continue;
            }

            $out[] = [
                'name' => (string) ($user['full_name'] ?? $user['fullName'] ?? $handle),
                'platform' => Platform::Instagram->value,
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
        $user = $this->unwrapInstagramUser($item);
        $username = (string) ($user['username'] ?? $user['user_name'] ?? $handle);

        if ($username === '' && ! isset($user['pk']) && ! isset($user['id']) && ! isset($user['instagram_pk'])) {
            return null;
        }

        $resolved = ltrim($username !== '' ? $username : $handle, '@');
        $externalId = $user['pk'] ?? $user['id'] ?? $user['instagram_pk'] ?? $user['user_id'] ?? null;
        $followers = $this->followerCountFromUser($user);

        $profile = [
            'platform' => $this->platform(),
            'handle' => $resolved,
            'url' => $this->profileUrl($resolved),
            'external_id' => $externalId !== null ? (string) $externalId : null,
            'avatar' => $user['profile_pic_url'] ?? $user['profilePicUrl'] ?? $user['avatar'] ?? null,
            'display_name' => $user['full_name'] ?? $user['fullName'] ?? $resolved,
        ];

        if ($followers !== null) {
            $profile['followers'] = $followers;
        }

        return $profile;
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function unwrapInstagramUser(array $item): array
    {
        $user = is_array($item['user'] ?? null) ? $item['user'] : $item;

        if (
            is_array($user['data'] ?? null)
            && ! isset($user['username'])
            && ! isset($user['pk'])
            && ! isset($user['id'])
        ) {
            $user = $user['data'];
        }

        return $user;
    }

    /**
     * @param  array<string, mixed>  $user
     */
    private function followerCountFromUser(array $user): ?int
    {
        $candidates = [
            $user['follower_count'] ?? null,
            $user['followers'] ?? null,
            $user['followersCount'] ?? null,
            data_get($user, 'edge_followed_by.count'),
        ];

        foreach ($candidates as $value) {
            if (is_numeric($value) && (int) $value >= 0) {
                return (int) $value;
            }
        }

        return null;
    }

    protected function mapPost(array $item, string $handle): ?array
    {
        $media = is_array($item['media'] ?? null) ? $item['media'] : $item;
        $code = (string) ($media['code'] ?? $media['shortcode'] ?? $media['shortCode'] ?? '');
        $url = (string) ($media['url'] ?? '');

        if ($url === '' && $code !== '') {
            $url = 'https://www.instagram.com/reel/'.$code.'/';
        }

        if ($url === '') {
            return null;
        }

        $mediaUrl = $this->firstVideoUrl(
            $media['video_url'] ?? null,
            $media['video_versions'] ?? null,
            data_get($media, 'video_versions.0.url'),
            data_get($media, 'clips_metadata.original_sound_info.progressive_download_url'),
        );

        if ($mediaUrl === null) {
            return null;
        }

        $hint = (string) ($media['product_type'] ?? $media['media_type'] ?? '');
        if (str_contains(strtolower($url), '/reel') || str_contains(strtolower($hint), 'clips')) {
            $hint = 'reel';
        }

        $type = $this->inferPostType($hint, $mediaUrl);

        if (! $this->isImportableReelType($type, $mediaUrl)) {
            return null;
        }

        return [
            'external_id' => isset($media['pk']) ? (string) $media['pk'] : ($code !== '' ? $code : (isset($media['id']) ? (string) $media['id'] : null)),
            'url' => $url,
            'posted_at' => $this->normalizeDate($media['taken_at'] ?? $media['device_timestamp'] ?? $media['caption']['created_at'] ?? null),
            'type' => PostType::Reel->value,
            'caption' => isset($media['caption']['text']) ? (string) $media['caption']['text'] : (isset($media['caption']) && is_string($media['caption']) ? $media['caption'] : null),
            'media_url' => $mediaUrl,
            'metrics' => $this->metrics(
                $media['play_count'] ?? $media['view_count'] ?? $media['ig_play_count'] ?? 0,
                $media['like_count'] ?? 0,
                $media['comment_count'] ?? 0,
                $media['share_count'] ?? 0,
            ),
            'raw_payload' => $item,
        ];
    }
}
