<?php

namespace App\Services\Apify\Adapters;

use App\Enums\Platform;
use App\Enums\PostType;

class InstagramAdapter extends AbstractPlatformAdapter
{
    public function platform(): Platform
    {
        return Platform::Instagram;
    }

    protected function actorId(): string
    {
        return (string) config('snitch.apify.actors.instagram');
    }

    protected function actorInput(string $handle, int $limit): array
    {
        $recencyDays = max(1, (int) config('snitch.sync.recency_days', 30));

        return [
            'directUrls' => [$this->profileUrl($handle)],
            'resultsType' => 'posts',
            // Profiles often lead with carousels; ask Apify for enough raw posts to fill reel quota after filtering.
            'resultsLimit' => max($limit, 12),
            'onlyPostsNewerThan' => "{$recencyDays} days",
        ];
    }

    protected function mapProfile(array $item, string $handle): ?array
    {
        $owner = is_array($item['owner'] ?? null) ? $item['owner'] : $item;

        if (! isset($owner['username']) && ! isset($owner['id']) && ! isset($item['ownerUsername'])) {
            return null;
        }

        // Error payloads from the actor (e.g. not_found) must not look like profiles.
        if (isset($item['error']) || isset($item['errorDescription'])) {
            return null;
        }

        $resolvedHandle = (string) ($item['ownerUsername'] ?? $owner['username'] ?? $handle);
        $externalId = $item['ownerId'] ?? $owner['id'] ?? null;

        return [
            'platform' => $this->platform(),
            'handle' => ltrim($resolvedHandle, '@'),
            'url' => $this->profileUrl(ltrim($resolvedHandle, '@')),
            'external_id' => $externalId !== null ? (string) $externalId : null,
            'avatar' => $owner['profilePicUrl'] ?? $item['ownerProfilePicUrl'] ?? null,
            'display_name' => $item['ownerFullName'] ?? $owner['fullName'] ?? $resolvedHandle,
        ];
    }

    protected function mapPost(array $item, string $handle): ?array
    {
        $url = (string) ($item['url'] ?? $item['shortCode'] ?? '');

        if ($url === '') {
            return null;
        }

        if (! str_starts_with($url, 'http')) {
            $url = 'https://www.instagram.com/reel/'.$url.'/';
        }

        $mediaUrl = $this->firstVideoUrl(
            $item['videoUrl'] ?? null,
            $item['video']['url'] ?? null,
        );

        if ($mediaUrl === null) {
            return null;
        }

        $hint = (string) ($item['type'] ?? $item['productType'] ?? '');
        if (str_contains(strtolower($url), '/reel')) {
            $hint = 'reel';
        }

        $type = $this->inferPostType($hint, $mediaUrl);

        if ($type === PostType::Video->value && (
            str_contains(strtolower($hint), 'clips')
            || str_contains(strtolower($url), '/reel')
        )) {
            $type = PostType::Reel->value;
        }

        if (! $this->isImportableReelType($type, $mediaUrl)) {
            return null;
        }

        $music = $item['musicInfo'] ?? $item['audio'] ?? null;

        return [
            'external_id' => isset($item['id']) ? (string) $item['id'] : (isset($item['shortCode']) ? (string) $item['shortCode'] : null),
            'url' => $url,
            'posted_at' => $this->normalizeDate($item['timestamp'] ?? $item['takenAt'] ?? $item['takenAtTimestamp'] ?? null),
            'type' => $type,
            'caption' => isset($item['caption']) ? (string) $item['caption'] : null,
            'media_url' => $mediaUrl,
            'metrics' => $this->metrics(
                $item['videoViewCount'] ?? $item['videoPlayCount'] ?? $item['playsCount'] ?? 0,
                $item['likesCount'] ?? $item['likeCount'] ?? 0,
                $item['commentsCount'] ?? $item['commentCount'] ?? 0,
                $item['sharesCount'] ?? 0,
            ),
            'raw_payload' => array_merge($item, is_array($music) ? ['normalized_music' => $music] : []),
        ];
    }
}
