<?php

namespace App\Services\TikHub\Adapters;

use App\Enums\Platform;
use App\Enums\PostType;
use App\Services\Apify\Contracts\PlatformAdapter;
use App\Services\TikHub\TikHubClient;
use Carbon\CarbonImmutable;

abstract class AbstractTikHubAdapter implements PlatformAdapter
{
    public function __construct(protected TikHubClient $client) {}

    abstract public function platform(): Platform;

    /**
     * @param  array<string, mixed>  $item
     * @return array{
     *     platform: Platform,
     *     handle: string,
     *     url: string,
     *     external_id: string|null,
     *     avatar: string|null,
     *     display_name: string|null
     * }|null
     */
    abstract protected function mapProfile(array $item, string $handle): ?array;

    /**
     * @param  array<string, mixed>  $item
     * @return array{
     *     external_id: string|null,
     *     url: string,
     *     posted_at: string|null,
     *     type: string,
     *     caption: string|null,
     *     media_url: string|null,
     *     metrics: array<string, mixed>,
     *     raw_payload: array<string, mixed>
     * }|null
     */
    abstract protected function mapPost(array $item, string $handle): ?array;

    public function hydrateMediaUrls(array $posts): array
    {
        return $posts;
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return array{
     *     platform: Platform,
     *     handle: string,
     *     url: string,
     *     external_id: string|null,
     *     avatar: string|null,
     *     display_name: string|null
     * }
     */
    protected function profileFromItems(array $items, string $handle): array
    {
        foreach ($items as $item) {
            $profile = $this->mapProfile($item, $handle);

            if ($profile !== null) {
                return $profile;
            }
        }

        return [
            'platform' => $this->platform(),
            'handle' => $handle,
            'url' => $this->profileUrl($handle),
            'external_id' => null,
            'avatar' => null,
            'display_name' => $handle,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return list<array{
     *     external_id: string|null,
     *     url: string,
     *     posted_at: string|null,
     *     type: string,
     *     caption: string|null,
     *     media_url: string|null,
     *     metrics: array<string, mixed>,
     *     raw_payload: array<string, mixed>
     * }>
     */
    protected function postsFromItems(array $items, string $handle, int $limit, ?CarbonImmutable $since = null): array
    {
        $posts = [];
        $recencyDays = max(1, (int) config('snitch.sync.recency_days', 30));
        $cutoff = $since ?? CarbonImmutable::now()->subDays($recencyDays);

        foreach ($items as $item) {
            $mapped = $this->mapPost($item, $handle);

            if ($mapped === null) {
                continue;
            }

            if (filled($mapped['posted_at'] ?? null)) {
                try {
                    if (CarbonImmutable::parse((string) $mapped['posted_at'])->lt($cutoff)) {
                        continue;
                    }
                } catch (\Throwable) {
                    // Keep when date is unparseable.
                }
            }

            $posts[] = $mapped;

            if (count($posts) >= $limit) {
                break;
            }
        }

        return $posts;
    }

    /**
     * Dig nested arrays for a list of post-like objects.
     *
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $keys
     * @return list<array<string, mixed>>
     */
    protected function extractList(array $payload, array $keys = []): array
    {
        $data = $payload['data'] ?? $payload;

        if (! is_array($data)) {
            return [];
        }

        foreach ($keys as $key) {
            $candidate = data_get($data, $key);

            if (is_array($candidate) && array_is_list($candidate)) {
                return array_values(array_filter($candidate, 'is_array'));
            }
        }

        if (array_is_list($data)) {
            return array_values(array_filter($data, 'is_array'));
        }

        foreach (['aweme_list', 'itemList', 'items', 'medias', 'posts', 'videos', 'data', 'user_list', 'userList'] as $key) {
            $candidate = $data[$key] ?? null;

            if (is_array($candidate) && array_is_list($candidate)) {
                return array_values(array_filter($candidate, 'is_array'));
            }
        }

        return [$data];
    }

    /**
     * @return array<string, mixed>
     */
    protected function extractObject(array $payload, array $keys = []): array
    {
        $data = $payload['data'] ?? $payload;

        if (! is_array($data)) {
            return [];
        }

        foreach ($keys as $key) {
            $candidate = data_get($data, $key);

            if (is_array($candidate) && ! array_is_list($candidate)) {
                return $candidate;
            }
        }

        if (! array_is_list($data)) {
            return $data;
        }

        $first = $data[0] ?? null;

        return is_array($first) ? $first : [];
    }

    protected function endpoint(string $key): string
    {
        $platform = $this->platform()->value;
        $path = (string) config("snitch.tikhub.endpoints.{$platform}.{$key}", '');

        if ($path === '') {
            throw new \RuntimeException("TikHub endpoint {$platform}.{$key} is not configured.");
        }

        return $path;
    }

    protected function normalizeHandle(string $handleOrUrl): string
    {
        $value = trim($handleOrUrl);

        if (str_contains($value, '://') || str_starts_with($value, 'www.')) {
            $path = parse_url(
                str_starts_with($value, 'http') ? $value : 'https://'.$value,
                PHP_URL_PATH,
            );

            if (is_string($path) && preg_match('#/@([^/]+)#', $path, $matches) === 1) {
                $value = $matches[1];
            } else {
                $value = is_string($path) ? basename(rtrim($path, '/')) : $value;
            }
        }

        return ltrim($value, '@');
    }

    protected function profileUrl(string $handle): string
    {
        return match ($this->platform()) {
            Platform::Instagram => "https://instagram.com/{$handle}",
            Platform::TikTok => "https://tiktok.com/@{$handle}",
            Platform::Facebook => "https://facebook.com/{$handle}",
            Platform::LinkedIn => "https://linkedin.com/company/{$handle}",
            Platform::Youtube => "https://youtube.com/@{$handle}",
        };
    }

    protected function inferPostType(?string $hint, ?string $mediaUrl): string
    {
        $hint = strtolower((string) $hint);
        $hasVideoMedia = $this->looksLikeVideoUrl($mediaUrl);

        return match (true) {
            str_contains($hint, 'carousel') || str_contains($hint, 'sidecar') => PostType::Carousel->value,
            str_contains($hint, 'reel') || str_contains($hint, 'short') || str_contains($hint, 'clips') => PostType::Reel->value,
            str_contains($hint, 'video') || $hasVideoMedia => PostType::Video->value,
            str_contains($hint, 'image') || str_contains($hint, 'photo') => PostType::Image->value,
            str_contains($hint, 'text') || str_contains($hint, 'status') => PostType::Text->value,
            default => $hasVideoMedia ? PostType::Video->value : PostType::Image->value,
        };
    }

    protected function looksLikeVideoUrl(?string $url): bool
    {
        if (! is_string($url) || $url === '') {
            return false;
        }

        $lower = strtolower($url);

        if (str_contains($lower, 'facebook.com/')
            || str_contains($lower, 'instagram.com/')
            || str_contains($lower, 'tiktok.com/')
            || str_contains($lower, 'linkedin.com/')
            || (str_contains($lower, 'youtube.com/') && ! str_contains($lower, '.mp4'))
            || str_contains($lower, 'youtu.be/')) {
            return false;
        }

        return str_contains($lower, '.mp4')
            || str_contains($lower, '.mov')
            || str_contains($lower, '.webm')
            || str_contains($lower, 'video')
            || str_contains($lower, 'fbcdn.net')
            || str_contains($lower, 'cdninstagram.com')
            || str_contains($lower, 'tiktokcdn');
    }

    protected function firstVideoUrl(mixed ...$candidates): ?string
    {
        foreach ($candidates as $candidate) {
            if (is_array($candidate)) {
                foreach ($candidate as $nested) {
                    $resolved = $this->firstVideoUrl($nested);

                    if ($resolved !== null) {
                        return $resolved;
                    }
                }

                continue;
            }

            if (! is_string($candidate) || trim($candidate) === '') {
                continue;
            }

            $url = trim($candidate);

            if ($this->looksLikeVideoUrl($url) || str_starts_with($url, 'http')) {
                if (preg_match('/\.(jpe?g|png|gif|webp|avif)(\?|$)/i', $url) === 1) {
                    continue;
                }

                return $url;
            }
        }

        return null;
    }

    protected function isImportableReelType(string $type, ?string $mediaUrl): bool
    {
        if (! in_array($type, PostType::analyzableValues(), true)) {
            return false;
        }

        return filled($mediaUrl);
    }

    protected function normalizeDate(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            if (is_numeric($value)) {
                $timestamp = (int) $value;

                if ($timestamp > 10_000_000_000) {
                    $timestamp = (int) floor($timestamp / 1000);
                }

                return CarbonImmutable::createFromTimestamp($timestamp)->toIso8601String();
            }

            return CarbonImmutable::parse((string) $value)->toIso8601String();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return array{views: int, likes: int, comments: int, shares: int}
     */
    protected function metrics(mixed ...$values): array
    {
        $keys = ['views', 'likes', 'comments', 'shares'];
        $metrics = [];

        foreach ($keys as $index => $key) {
            $metrics[$key] = (int) ($values[$index] ?? 0);
        }

        return $metrics;
    }
}
