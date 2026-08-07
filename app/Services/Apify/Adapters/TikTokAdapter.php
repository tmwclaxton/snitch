<?php

namespace App\Services\Apify\Adapters;

use App\Enums\Platform;
use App\Enums\PostType;

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

    protected function actorInput(string $handle, int $limit): array
    {
        return [
            'profiles' => [$handle],
            'resultsPerPage' => $limit,
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

        $music = is_array($item['musicMeta'] ?? null) ? $item['musicMeta'] : (is_array($item['music'] ?? null) ? $item['music'] : null);

        return [
            'external_id' => isset($item['id']) ? (string) $item['id'] : null,
            'url' => $url,
            'posted_at' => $this->normalizeDate($item['createTime'] ?? $item['createTimeISO'] ?? null),
            'type' => PostType::Video->value,
            'caption' => isset($item['text']) ? (string) $item['text'] : (isset($item['desc']) ? (string) $item['desc'] : null),
            'media_url' => $item['videoUrl'] ?? $item['mediaUrls'][0] ?? $item['covers'][0] ?? null,
            'metrics' => $this->metrics(
                $item['playCount'] ?? $item['videoMeta']['playCount'] ?? 0,
                $item['diggCount'] ?? $item['likes'] ?? 0,
                $item['commentCount'] ?? 0,
                $item['shareCount'] ?? 0,
            ),
            'raw_payload' => array_merge($item, is_array($music) ? ['normalized_music' => $music] : []),
        ];
    }
}
