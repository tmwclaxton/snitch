<?php

namespace App\Services\Apify\Adapters;

use App\Enums\Platform;
use App\Enums\PostType;
use Carbon\CarbonImmutable;

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

    protected function actorInput(string $handle, int $limit, ?CarbonImmutable $since = null): array
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

        $isVideo = (bool) ($item['isVideo'] ?? false)
            || str_contains(strtolower($url), '/reel')
            || str_contains(strtolower($url), '/videos/');

        if (! $isVideo) {
            return null;
        }

        $mediaUrl = $this->firstVideoUrl(
            $item['videoUrl'] ?? null,
            $item['video']['url'] ?? null,
            $item['media'][0]['videoDeliveryLegacyFields']['browser_native_hd_url'] ?? null,
            $item['media'][0]['videoDeliveryLegacyFields']['browser_native_sd_url'] ?? null,
            $item['media'][0]['videoUrl'] ?? null,
        );

        // Reject facebook.com page URLs - they are not analyzable video files.
        if ($mediaUrl === null || str_contains($mediaUrl, 'facebook.com/')) {
            return null;
        }

        $type = PostType::Reel->value;
        if (! str_contains(strtolower($url), '/reel')) {
            $type = PostType::Video->value;
        }

        return [
            'external_id' => isset($item['postId']) ? (string) $item['postId'] : (isset($item['id']) ? (string) $item['id'] : null),
            'url' => $url,
            'posted_at' => $this->normalizeDate($item['time'] ?? $item['timestamp'] ?? $item['created_time'] ?? null),
            'type' => $type,
            'caption' => isset($item['text']) ? (string) $item['text'] : (isset($item['message']) ? (string) $item['message'] : null),
            'media_url' => $mediaUrl,
            'metrics' => $this->metrics(
                $item['viewsCount'] ?? $item['videoViewCount'] ?? $item['videoPostViewCount'] ?? 0,
                $item['likes'] ?? $item['likesCount'] ?? 0,
                $item['comments'] ?? $item['commentsCount'] ?? 0,
                $item['shares'] ?? $item['sharesCount'] ?? 0,
            ),
            'raw_payload' => $item,
        ];
    }
}
