<?php

namespace App\Services\Apify\Adapters;

use App\Enums\Platform;
use App\Enums\PostType;
use App\Services\Apify\ApifyClient;
use App\Services\Apify\Contracts\PlatformAdapter;
use Carbon\CarbonImmutable;

abstract class AbstractPlatformAdapter implements PlatformAdapter
{
    public function __construct(protected ApifyClient $client) {}

    abstract public function platform(): Platform;

    abstract protected function actorId(): string;

    /**
     * @return array<string, mixed>
     */
    abstract protected function actorInput(string $handle, int $limit): array;

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

    public function resolveProfile(string $handleOrUrl): array
    {
        $handle = $this->normalizeHandle($handleOrUrl);
        $items = $this->client->runActor($this->actorId(), $this->actorInput($handle, 1));

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

    public function listRecentPosts(string $handleOrUrl, int $limit = 12): array
    {
        $handle = $this->normalizeHandle($handleOrUrl);
        $items = $this->client->runActor($this->actorId(), $this->actorInput($handle, $limit));
        $posts = [];

        foreach ($items as $item) {
            $mapped = $this->mapPost($item, $handle);

            if ($mapped === null) {
                continue;
            }

            $posts[] = $mapped;

            if (count($posts) >= $limit) {
                break;
            }
        }

        return $posts;
    }

    /**
     * Map fixture actor items without calling Apify.
     *
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
    public function mapFixturePosts(array $items, string $handle = 'demo'): array
    {
        $posts = [];

        foreach ($items as $item) {
            $mapped = $this->mapPost($item, $handle);

            if ($mapped !== null) {
                $posts[] = $mapped;
            }
        }

        return $posts;
    }

    protected function normalizeHandle(string $handleOrUrl): string
    {
        $value = trim($handleOrUrl);

        if (str_contains($value, '://') || str_starts_with($value, 'www.')) {
            $path = parse_url(
                str_starts_with($value, 'http') ? $value : 'https://'.$value,
                PHP_URL_PATH,
            );
            $value = is_string($path) ? basename(rtrim($path, '/')) : $value;
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
            Platform::Pinterest => "https://pinterest.com/{$handle}",
        };
    }

    protected function inferPostType(?string $hint, ?string $mediaUrl): string
    {
        $hint = strtolower((string) $hint);

        return match (true) {
            str_contains($hint, 'carousel') || str_contains($hint, 'sidecar') => PostType::Carousel->value,
            str_contains($hint, 'reel') => PostType::Reel->value,
            str_contains($hint, 'video') => PostType::Video->value,
            str_contains($hint, 'image') || str_contains($hint, 'photo') => PostType::Image->value,
            is_string($mediaUrl) && str_contains($mediaUrl, '.mp4') => PostType::Video->value,
            default => PostType::Image->value,
        };
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
