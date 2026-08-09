<?php

namespace App\Services\Apify\Adapters;

use App\Enums\Platform;
use App\Enums\PostType;
use Carbon\CarbonImmutable;

class YoutubeAdapter extends AbstractPlatformAdapter
{
    public function platform(): Platform
    {
        return Platform::Youtube;
    }

    protected function actorId(): string
    {
        return (string) config('snitch.apify.actors.youtube');
    }

    protected function actorInput(string $handle, int $limit, ?CarbonImmutable $since = null): array
    {
        return [
            // Channel Shorts tab is required; plain channel URL often returns about-only.
            'startUrls' => [['url' => $this->profileUrl($handle).'/shorts']],
            // Shorts only: zero regular videos / streams.
            'maxResults' => 0,
            'maxResultsShorts' => $limit,
            'maxResultStreams' => 0,
            'oldestPostDate' => $this->dateFilterValue($since),
            'sortVideosBy' => 'NEWEST',
        ];
    }

    /**
     * YouTube search seed: query returns videos/channels; callers extract unique channel handles.
     *
     * @return array{actorId: string, input: array<string, mixed>}
     */
    public function searchChannelsActorJob(string $query, int $limit): array
    {
        return [
            'actorId' => $this->actorId(),
            'input' => $this->searchChannelsActorInput($query, $limit),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function searchChannelsActorInput(string $query, int $limit): array
    {
        return [
            'searchQueries' => [$query],
            // Prefer Shorts-active creators; still extract channel metadata from each hit.
            'maxResults' => 0,
            'maxResultsShorts' => max(1, $limit),
            'maxResultStreams' => 0,
            'sortingOrder' => 'relevance',
        ];
    }

    protected function mapProfile(array $item, string $handle): ?array
    {
        $about = is_array($item['aboutChannelInfo'] ?? null) ? $item['aboutChannelInfo'] : [];
        $error = (string) ($item['error'] ?? '');

        // Actor emits CHANNEL_HAS_NO_SHORTS with aboutChannelInfo - still a valid profile.
        if ($error !== '' && $error !== 'CHANNEL_HAS_NO_SHORTS' && $about === []) {
            return null;
        }

        $channelHandle = (string) ($item['channelUsername'] ?? $about['channelUsername'] ?? $handle);
        $channelHandle = ltrim($channelHandle, '@');
        $channelId = $item['channelId'] ?? $about['channelId'] ?? null;
        $displayName = (string) ($item['channelName'] ?? $about['channelName'] ?? $channelHandle);
        $avatar = $item['channelAvatarUrl'] ?? $about['channelAvatarUrl'] ?? null;

        if ($channelHandle === '' && $channelId === null && $displayName === '') {
            return null;
        }

        $resolved = $channelHandle !== '' ? $channelHandle : $handle;

        return [
            'platform' => $this->platform(),
            'handle' => $resolved,
            'url' => $this->profileUrl($resolved),
            'external_id' => $channelId !== null ? (string) $channelId : null,
            'avatar' => is_string($avatar) ? $avatar : null,
            'display_name' => $displayName !== '' ? $displayName : $resolved,
        ];
    }

    protected function mapPost(array $item, string $handle): ?array
    {
        if (isset($item['error']) || isset($item['errorDescription'])) {
            return null;
        }

        if (! $this->isShort($item)) {
            return null;
        }

        $id = isset($item['id']) ? (string) $item['id'] : (isset($item['videoId']) ? (string) $item['videoId'] : null);
        $url = (string) ($item['url'] ?? $item['shortsUrl'] ?? '');

        if ($url === '' && $id !== null) {
            $url = "https://www.youtube.com/shorts/{$id}";
        }

        if ($url === '') {
            return null;
        }

        $mediaUrl = $this->firstVideoUrl(
            $item['videoUrl'] ?? null,
            $item['downloadUrl'] ?? null,
            $item['mediaUrl'] ?? null,
            $item['streamingData']['formats'][0]['url'] ?? null,
        );

        // Prefer a downloadable file when present; otherwise use the Shorts URL for embed + best-effort analysis.
        if ($mediaUrl === null) {
            $mediaUrl = $url;
        }

        return [
            'external_id' => $id,
            'url' => $url,
            'posted_at' => $this->normalizeDate($item['date'] ?? $item['uploadDate'] ?? $item['publishedAt'] ?? null),
            'type' => PostType::Reel->value,
            'caption' => isset($item['title']) ? (string) $item['title'] : (isset($item['text']) ? (string) $item['text'] : null),
            'media_url' => $mediaUrl,
            'metrics' => $this->metrics(
                $item['viewCount'] ?? $item['views'] ?? 0,
                $item['likes'] ?? $item['likeCount'] ?? 0,
                $item['commentsCount'] ?? $item['commentCount'] ?? 0,
                0,
            ),
            'raw_payload' => $item,
        ];
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function isShort(array $item): bool
    {
        $url = strtolower((string) ($item['url'] ?? $item['shortsUrl'] ?? ''));
        $type = strtolower((string) ($item['type'] ?? $item['videoType'] ?? ''));

        if (str_contains($url, '/shorts/') || str_contains($type, 'short')) {
            return true;
        }

        if (isset($item['isShort']) && (bool) $item['isShort']) {
            return true;
        }

        $seconds = $this->durationSeconds($item['duration'] ?? $item['durationSec'] ?? null);

        return $seconds !== null && $seconds > 0 && $seconds <= 60;
    }

    private function durationSeconds(mixed $duration): ?int
    {
        if (is_numeric($duration)) {
            return (int) $duration;
        }

        if (! is_string($duration) || $duration === '') {
            return null;
        }

        if (preg_match('/^PT(?:(\d+)H)?(?:(\d+)M)?(?:(\d+)S)?$/', $duration, $matches) === 1) {
            return ((int) ($matches[1] ?? 0) * 3600)
                + ((int) ($matches[2] ?? 0) * 60)
                + (int) ($matches[3] ?? 0);
        }

        if (preg_match('/^(?:(\d+):)?(\d{1,2}):(\d{2})$/', $duration, $matches) === 1) {
            return ((int) ($matches[1] ?? 0) * 3600)
                + ((int) $matches[2] * 60)
                + (int) $matches[3];
        }

        return null;
    }
}
