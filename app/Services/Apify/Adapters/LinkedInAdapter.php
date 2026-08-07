<?php

namespace App\Services\Apify\Adapters;

use App\Enums\Platform;

class LinkedInAdapter extends AbstractPlatformAdapter
{
    public function platform(): Platform
    {
        return Platform::LinkedIn;
    }

    protected function actorId(): string
    {
        return (string) config('snitch.apify.actors.linkedin');
    }

    protected function actorInput(string $handle, int $limit): array
    {
        return [
            'urls' => [$this->profileUrl($handle)],
            'limit' => $limit,
        ];
    }

    protected function mapProfile(array $item, string $handle): ?array
    {
        $author = is_array($item['author'] ?? null) ? $item['author'] : [];

        return [
            'platform' => $this->platform(),
            'handle' => $handle,
            'url' => $this->profileUrl($handle),
            'external_id' => isset($author['id']) ? (string) $author['id'] : null,
            'avatar' => $author['image'] ?? $author['avatar'] ?? null,
            'display_name' => $author['name'] ?? $handle,
        ];
    }

    protected function mapPost(array $item, string $handle): ?array
    {
        $url = (string) ($item['url'] ?? $item['postUrl'] ?? $item['shareUrl'] ?? '');

        if ($url === '') {
            return null;
        }

        $mediaUrl = $item['video']['url'] ?? $item['images'][0] ?? $item['mediaUrl'] ?? null;

        return [
            'external_id' => isset($item['urn']) ? (string) $item['urn'] : (isset($item['id']) ? (string) $item['id'] : null),
            'url' => $url,
            'posted_at' => $this->normalizeDate($item['postedAt'] ?? $item['publishedAt'] ?? $item['timestamp'] ?? null),
            'type' => $this->inferPostType((string) ($item['type'] ?? ''), is_string($mediaUrl) ? $mediaUrl : null),
            'caption' => isset($item['text']) ? (string) $item['text'] : (isset($item['commentary']) ? (string) $item['commentary'] : null),
            'media_url' => is_string($mediaUrl) ? $mediaUrl : null,
            'metrics' => $this->metrics(
                $item['views'] ?? 0,
                $item['likes'] ?? $item['numLikes'] ?? 0,
                $item['comments'] ?? $item['numComments'] ?? 0,
                $item['shares'] ?? $item['numShares'] ?? 0,
            ),
            'raw_payload' => $item,
        ];
    }
}
