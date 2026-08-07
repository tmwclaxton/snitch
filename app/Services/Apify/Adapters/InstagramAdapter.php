<?php

namespace App\Services\Apify\Adapters;

use App\Enums\Platform;

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
        return [
            'directUrls' => [$this->profileUrl($handle)],
            'resultsType' => 'posts',
            'resultsLimit' => $limit,
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
            $url = 'https://www.instagram.com/p/'.$url.'/';
        }

        $mediaUrl = $item['videoUrl'] ?? $item['displayUrl'] ?? $item['imageUrl'] ?? null;
        $type = $this->inferPostType((string) ($item['type'] ?? $item['productType'] ?? ''), is_string($mediaUrl) ? $mediaUrl : null);

        $music = $item['musicInfo'] ?? $item['audio'] ?? null;

        return [
            'external_id' => isset($item['id']) ? (string) $item['id'] : (isset($item['shortCode']) ? (string) $item['shortCode'] : null),
            'url' => $url,
            'posted_at' => $this->normalizeDate($item['timestamp'] ?? $item['takenAt'] ?? $item['takenAtTimestamp'] ?? null),
            'type' => $type,
            'caption' => isset($item['caption']) ? (string) $item['caption'] : null,
            'media_url' => is_string($mediaUrl) ? $mediaUrl : null,
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
