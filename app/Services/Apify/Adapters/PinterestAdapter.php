<?php

namespace App\Services\Apify\Adapters;

use App\Enums\Platform;
use App\Enums\PostType;

class PinterestAdapter extends AbstractPlatformAdapter
{
    public function platform(): Platform
    {
        return Platform::Pinterest;
    }

    protected function actorId(): string
    {
        return (string) config('snitch.apify.actors.pinterest');
    }

    protected function actorInput(string $handle, int $limit): array
    {
        return [
            'username' => $handle,
            'maxItems' => $limit,
        ];
    }

    protected function mapProfile(array $item, string $handle): ?array
    {
        $user = is_array($item['pinner'] ?? null) ? $item['pinner'] : (is_array($item['user'] ?? null) ? $item['user'] : []);

        return [
            'platform' => $this->platform(),
            'handle' => (string) ($user['username'] ?? $handle),
            'url' => $this->profileUrl((string) ($user['username'] ?? $handle)),
            'external_id' => isset($user['id']) ? (string) $user['id'] : null,
            'avatar' => $user['image_large_url'] ?? $user['avatar'] ?? null,
            'display_name' => $user['full_name'] ?? $user['username'] ?? $handle,
        ];
    }

    protected function mapPost(array $item, string $handle): ?array
    {
        $url = (string) ($item['link'] ?? $item['url'] ?? $item['pinUrl'] ?? '');

        if ($url === '') {
            $id = $item['id'] ?? null;

            if ($id === null) {
                return null;
            }

            $url = "https://www.pinterest.com/pin/{$id}/";
        }

        $mediaUrl = $item['image']['url'] ?? $item['images']['orig']['url'] ?? $item['imageUrl'] ?? null;
        $isVideo = isset($item['videos']) || ($item['is_video'] ?? false);

        return [
            'external_id' => isset($item['id']) ? (string) $item['id'] : null,
            'url' => $url,
            'posted_at' => $this->normalizeDate($item['created_at'] ?? $item['createdAt'] ?? null),
            'type' => $isVideo ? PostType::Video->value : PostType::Image->value,
            'caption' => isset($item['description']) ? (string) $item['description'] : (isset($item['title']) ? (string) $item['title'] : null),
            'media_url' => is_string($mediaUrl) ? $mediaUrl : null,
            'metrics' => $this->metrics(
                $item['view_count'] ?? 0,
                $item['repin_count'] ?? $item['saves'] ?? 0,
                $item['comment_count'] ?? 0,
                $item['share_count'] ?? 0,
            ),
            'raw_payload' => $item,
        ];
    }
}
