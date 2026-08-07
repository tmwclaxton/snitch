<?php

namespace App\Services\Apify\Contracts;

use App\Enums\Platform;

interface PlatformAdapter
{
    public function platform(): Platform;

    /**
     * @return array{
     *     platform: Platform,
     *     handle: string,
     *     url: string,
     *     external_id: string|null,
     *     avatar: string|null,
     *     display_name: string|null
     * }
     */
    public function resolveProfile(string $handleOrUrl): array;

    /**
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
    public function listRecentPosts(string $handleOrUrl, int $limit = 12): array;
}
