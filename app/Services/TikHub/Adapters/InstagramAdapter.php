<?php

namespace App\Services\TikHub\Adapters;

use App\Enums\Platform;
use App\Enums\PostType;
use App\Models\SocialAccount;
use App\Support\InstagramMetrics;
use App\Support\InstagramPostId;
use Carbon\CarbonImmutable;
use RuntimeException;
use Throwable;

class InstagramAdapter extends AbstractTikHubAdapter
{
    public function platform(): Platform
    {
        return Platform::Instagram;
    }

    public function resolveProfile(string $handleOrUrl): array
    {
        $handle = $this->normalizeHandle($handleOrUrl);
        $userId = SocialAccount::query()
            ->where('platform', Platform::Instagram)
            ->where('handle', $handle)
            ->whereNotNull('external_id')
            ->value('external_id');

        $attempts = [
            ['user_info', ['username' => $handle]],
        ];

        if (filled($userId)) {
            $attempts[] = ['user_info', [
                'username' => $handle,
                'user_id' => (string) $userId,
            ]];
        }

        $attempts[] = ['user_info_by_username', ['username' => $handle]];

        $lastError = null;
        $lastProfile = null;

        foreach ($attempts as [$key, $query]) {
            try {
                $payload = $this->client->get($this->endpoint($key), $query, 'instagram');
                $item = $this->extractObject($payload, ['data.data', 'data', 'user', 'data.user', 'user_info']);
                $profile = $this->profileFromItems([$item !== [] ? $item : $payload], $handle);

                if (isset($profile['followers']) && is_numeric($profile['followers'])) {
                    return $profile;
                }

                $lastProfile = $profile;
            } catch (Throwable $exception) {
                $lastError = $exception;
            }
        }

        if (is_array($lastProfile)) {
            return $lastProfile;
        }

        throw $lastError ?? new RuntimeException('TikHub Instagram profile lookup failed.');
    }

    public function listRecentPosts(string $handleOrUrl, int $limit = 12, ?CarbonImmutable $since = null): array
    {
        $handle = $this->normalizeHandle($handleOrUrl);
        $fetch = max($limit, (int) ceil($limit * 2.5));

        $postError = null;
        $reelError = null;

        try {
            $postItems = $this->fetchUserMediaList('user_posts', $handle, $fetch);
        } catch (Throwable $e) {
            $postError = $e;
            $postItems = [];
        }

        try {
            $reelItems = $this->fetchUserMediaList('user_reels', $handle, $fetch);
        } catch (Throwable $e) {
            $reelError = $e;
            $reelItems = [];
        }

        if ($postItems === [] && $reelItems === [] && ($postError !== null || $reelError !== null)) {
            throw $postError ?? $reelError;
        }

        $merged = $this->sortMediaItemsByRecency(
            $this->mergeMediaItems($postItems, $reelItems),
        );

        return $this->postsFromItems($merged, $handle, $limit, $since);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchUserMediaList(string $endpointKey, string $handle, int $count): array
    {
        $payload = $this->client->get($this->endpoint($endpointKey), [
            'username' => $handle,
            'count' => $count,
        ], 'instagram');

        return $this->extractList($payload, [
            'items',
            'data.items',
            'reels',
            'data.reels',
            'medias',
            'data.medias',
            'posts',
            'data.posts',
        ]);
    }

    /**
     * Merge posts + reels feeds and dedupe by canonical shortcode.
     *
     * @param  list<array<string, mixed>>  $postItems
     * @param  list<array<string, mixed>>  $reelItems
     * @return list<array<string, mixed>>
     */
    private function mergeMediaItems(array $postItems, array $reelItems): array
    {
        $byCode = [];

        foreach ([$postItems, $reelItems] as $batch) {
            foreach ($batch as $item) {
                if (! is_array($item)) {
                    continue;
                }

                $media = is_array($item['media'] ?? null) ? $item['media'] : $item;
                $code = InstagramPostId::fromPayload($media)
                    ?? InstagramPostId::fromPayload($item)
                    ?? InstagramPostId::fromUrl((string) ($media['url'] ?? $item['url'] ?? ''));

                if ($code === null || $code === '') {
                    $byCode['__anon_'.count($byCode)] = $item;

                    continue;
                }

                // Prefer the richer payload when both feeds return the same shortcode.
                if (! isset($byCode[$code]) || $this->mediaPayloadScore($item) > $this->mediaPayloadScore($byCode[$code])) {
                    $byCode[$code] = $item;
                }
            }
        }

        return array_values($byCode);
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    private function sortMediaItemsByRecency(array $items): array
    {
        usort($items, function (array $left, array $right): int {
            return $this->mediaTakenAt($right) <=> $this->mediaTakenAt($left);
        });

        return $items;
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function mediaTakenAt(array $item): int
    {
        $media = is_array($item['media'] ?? null) ? $item['media'] : $item;
        $value = $media['taken_at'] ?? $media['device_timestamp'] ?? 0;

        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function mediaPayloadScore(array $item): int
    {
        $media = is_array($item['media'] ?? null) ? $item['media'] : $item;
        $score = 0;

        if (isset($media['video_versions']) || isset($media['video_url'])) {
            $score += 2;
        }

        if (isset($media['image_versions2']) || isset($media['display_uri']) || isset($media['thumbnail_url'])) {
            $score += 1;
        }

        if (isset($media['carousel_media']) && is_array($media['carousel_media'])) {
            $score += 1;
        }

        if (isset($media['like_count']) || isset($media['play_count']) || isset($media['comment_count'])) {
            $score += 1;
        }

        return $score;
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
        $code = InstagramPostId::fromPayload($media) ?? InstagramPostId::fromPayload($item);
        $type = $this->instagramPostType($media);
        $url = (string) ($media['url'] ?? $item['url'] ?? '');

        if ($url === '' && $code !== null && $code !== '') {
            $url = $type === PostType::Reel->value
                ? 'https://www.instagram.com/reel/'.$code.'/'
                : 'https://www.instagram.com/p/'.$code.'/';
        }

        if ($url === '') {
            return null;
        }

        $videoUrl = $this->firstVideoUrl(
            $media['video_url'] ?? null,
            $media['video_versions'] ?? null,
            data_get($media, 'video_versions.0.url'),
            data_get($media, 'clips_metadata.original_sound_info.progressive_download_url'),
        );
        $stillUrl = $this->firstStillUrl(
            $media['display_uri'] ?? null,
            $media['thumbnail_url'] ?? null,
            $media['image_url'] ?? null,
            data_get($media, 'image_versions2.candidates.0.url'),
            data_get($media, 'image_versions2.candidates'),
            data_get($media, 'carousel_media.0.image_versions2.candidates.0.url'),
            data_get($media, 'carousel_media.0.display_uri'),
        );

        $mediaUrl = in_array($type, PostType::analyzableValues(), true)
            ? $videoUrl
            : ($stillUrl ?? $videoUrl);

        if ($mediaUrl === null) {
            return null;
        }

        if (in_array($type, PostType::analyzableValues(), true) && ! $this->isImportableReelType($type, $mediaUrl)) {
            return null;
        }

        $metrics = InstagramMetrics::metricsFromPayload(
            $media,
            (int) ($media['share_count'] ?? $media['shares'] ?? 0),
            $this->clicksFrom(is_array($media) ? $media : []),
            $this->followerCountFromUser(is_array($media['user'] ?? null) ? $media['user'] : (is_array($item['user'] ?? null) ? $item['user'] : [])),
        );

        return [
            'external_id' => $code ?? InstagramPostId::fromUrl($url),
            'url' => $url,
            'posted_at' => $this->normalizeDate($media['taken_at'] ?? $media['device_timestamp'] ?? $media['caption']['created_at'] ?? null),
            'type' => $type,
            'caption' => isset($media['caption']['text']) ? (string) $media['caption']['text'] : (isset($media['caption']) && is_string($media['caption']) ? $media['caption'] : null),
            'media_url' => $mediaUrl,
            'metrics' => $metrics,
            'raw_payload' => $item,
        ];
    }

    /**
     * @param  array<string, mixed>  $media
     */
    private function instagramPostType(array $media): string
    {
        $productType = strtolower((string) ($media['product_type'] ?? $media['productType'] ?? ''));
        $mediaType = $media['media_type'] ?? $media['mediaType'] ?? null;
        $url = strtolower((string) ($media['url'] ?? ''));

        if (
            $productType === 'clips'
            || str_contains($productType, 'clip')
            || str_contains($url, '/reel')
        ) {
            return PostType::Reel->value;
        }

        if (
            (is_numeric($mediaType) && (int) $mediaType === 8)
            || str_contains($productType, 'carousel')
            || isset($media['carousel_media'])
        ) {
            return PostType::Carousel->value;
        }

        if (is_numeric($mediaType) && (int) $mediaType === 1) {
            return PostType::Image->value;
        }

        if (is_numeric($mediaType) && (int) $mediaType === 2) {
            return PostType::Video->value;
        }

        $hint = (string) ($media['product_type'] ?? $media['media_type'] ?? '');
        $probeUrl = $this->firstVideoUrl(
            $media['video_url'] ?? null,
            data_get($media, 'video_versions.0.url'),
        ) ?? $this->firstStillUrl(
            $media['display_uri'] ?? null,
            data_get($media, 'image_versions2.candidates.0.url'),
        );

        return $this->inferPostType($hint, $probeUrl);
    }
}
