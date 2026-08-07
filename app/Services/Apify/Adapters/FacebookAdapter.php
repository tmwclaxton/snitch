<?php

namespace App\Services\Apify\Adapters;

use App\Enums\Platform;

class FacebookAdapter extends AbstractPlatformAdapter
{
    public function platform(): Platform
    {
        return Platform::Facebook;
    }

    protected function actorId(): string
    {
        return (string) config('snitch.apify.actors.facebook');
    }

    protected function actorInput(string $handle, int $limit): array
    {
        return [
            'startUrls' => [['url' => $this->profileUrl($handle)]],
            'resultsLimit' => $limit,
        ];
    }

    protected function mapProfile(array $item, string $handle): ?array
    {
        $pageName = (string) ($item['pageName'] ?? $item['user']['name'] ?? $handle);

        return [
            'platform' => $this->platform(),
            'handle' => $handle,
            'url' => $this->profileUrl($handle),
            'external_id' => isset($item['pageId']) ? (string) $item['pageId'] : (isset($item['user']['id']) ? (string) $item['user']['id'] : null),
            'avatar' => $item['pageProfilePictureUrl'] ?? $item['user']['profilePic'] ?? null,
            'display_name' => $pageName,
        ];
    }

    protected function mapPost(array $item, string $handle): ?array
    {
        $url = (string) ($item['url'] ?? $item['postUrl'] ?? '');

        if ($url === '') {
            return null;
        }

        $mediaUrl = $item['videoUrl'] ?? $item['media'][0]['thumbnail'] ?? $item['image'] ?? null;

        return [
            'external_id' => isset($item['postId']) ? (string) $item['postId'] : (isset($item['id']) ? (string) $item['id'] : null),
            'url' => $url,
            'posted_at' => $this->normalizeDate($item['time'] ?? $item['timestamp'] ?? $item['created_time'] ?? null),
            'type' => $this->inferPostType((string) ($item['type'] ?? ''), is_string($mediaUrl) ? $mediaUrl : null),
            'caption' => isset($item['text']) ? (string) $item['text'] : (isset($item['message']) ? (string) $item['message'] : null),
            'media_url' => is_string($mediaUrl) ? $mediaUrl : null,
            'metrics' => $this->metrics(
                $item['viewsCount'] ?? $item['videoViewCount'] ?? 0,
                $item['likes'] ?? $item['likesCount'] ?? 0,
                $item['comments'] ?? $item['commentsCount'] ?? 0,
                $item['shares'] ?? $item['sharesCount'] ?? 0,
            ),
            'raw_payload' => $item,
        ];
    }
}
