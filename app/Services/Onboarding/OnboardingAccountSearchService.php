<?php

namespace App\Services\Onboarding;

use App\Enums\Platform;
use App\Models\SocialAccount;
use App\Models\TrackedAccount;
use App\Models\User;
use App\Services\Apify\PlatformAdapterManager;
use App\Support\SocialHandle;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

class OnboardingAccountSearchService
{
    public function __construct(private PlatformAdapterManager $adapters) {}

    /**
     * @return list<array{
     *     platform: string,
     *     handle: string,
     *     display_name: string|null,
     *     avatar: string|null,
     *     followers: int|null,
     *     source: string
     * }>
     */
    public function search(string $query, User $user, int $limit = 12): array
    {
        $q = trim($query);

        if ($q === '') {
            return [];
        }

        $needle = ltrim(mb_strtolower($q), '@');
        $like = '%'.$needle.'%';

        $fromTracked = TrackedAccount::query()
            ->where(function ($builder) use ($like): void {
                $builder->where('handle', 'like', $like)
                    ->orWhere('display_name', 'like', $like);
            })
            ->orderByDesc('followers')
            ->limit($limit * 2)
            ->get(['platform', 'handle', 'display_name', 'avatar', 'followers']);

        $fromSocial = SocialAccount::query()
            ->where(function ($builder) use ($like): void {
                $builder->where('handle', 'like', $like)
                    ->orWhere('display_name', 'like', $like);
            })
            ->limit($limit * 2)
            ->get(['platform', 'handle', 'display_name', 'avatar', 'id']);

        $socialIds = $fromSocial->pluck('id')->filter()->all();
        $followersBySocial = $socialIds === []
            ? collect()
            : TrackedAccount::query()
                ->whereIn('social_account_id', $socialIds)
                ->whereNotNull('followers')
                ->orderByDesc('followers')
                ->get(['social_account_id', 'followers'])
                ->unique('social_account_id')
                ->keyBy('social_account_id');

        /** @var Collection<string, array{platform: string, handle: string, display_name: string|null, avatar: string|null, followers: int|null, source: string}> $rows */
        $rows = collect();

        foreach ($fromTracked as $account) {
            $platform = $account->platform instanceof Platform
                ? $account->platform->value
                : (string) $account->platform;
            $handle = mb_strtolower((string) $account->handle);
            $key = $platform.':'.$handle;

            if ($rows->has($key)) {
                continue;
            }

            $rows->put($key, [
                'platform' => $platform,
                'handle' => $handle,
                'display_name' => $account->display_name,
                'avatar' => $account->avatar,
                'followers' => $account->followers,
                'source' => 'tracked',
            ]);
        }

        foreach ($fromSocial as $account) {
            $platform = $account->platform instanceof Platform
                ? $account->platform->value
                : (string) $account->platform;
            $handle = mb_strtolower((string) $account->handle);
            $key = $platform.':'.$handle;

            if ($rows->has($key)) {
                continue;
            }

            $followers = $followersBySocial->get($account->id)?->followers;

            $rows->put($key, [
                'platform' => $platform,
                'handle' => $handle,
                'display_name' => $account->display_name,
                'avatar' => $account->avatar,
                'followers' => $followers !== null ? (int) $followers : null,
                'source' => 'corpus',
            ]);
        }

        $already = $user->trackedAccounts()
            ->competitors()
            ->get(['platform', 'handle'])
            ->map(function (TrackedAccount $account): string {
                $platform = $account->platform instanceof Platform
                    ? $account->platform->value
                    : (string) $account->platform;

                return $platform.':'.mb_strtolower((string) $account->handle);
            })
            ->all();

        return $rows
            ->reject(fn (array $row): bool => in_array($row['platform'].':'.$row['handle'], $already, true))
            ->sortByDesc(fn (array $row): int => (int) ($row['followers'] ?? 0))
            ->take($limit)
            ->values()
            ->all();
    }

    /**
     * Live resolve a handle or profile URL when it is not already in the corpus.
     *
     * @return array{
     *     platform: string,
     *     handle: string,
     *     display_name: string|null,
     *     avatar: string|null,
     *     followers: int|null,
     *     source: string,
     *     url: string|null
     * }|null
     */
    public function lookup(string $input, ?Platform $platform = null): ?array
    {
        $raw = trim($input);

        if ($raw === '') {
            return null;
        }

        $resolvedPlatform = $platform ?? $this->guessPlatform($raw) ?? Platform::Instagram;
        $handle = $this->extractHandle($raw, $resolvedPlatform);

        if ($handle === '' || SocialHandle::isWeak($handle, $resolvedPlatform)) {
            return null;
        }

        $cached = TrackedAccount::query()
            ->where('platform', $resolvedPlatform)
            ->where('handle', mb_strtolower($handle))
            ->orderByDesc('followers')
            ->first();

        if ($cached !== null) {
            return [
                'platform' => $resolvedPlatform->value,
                'handle' => mb_strtolower((string) $cached->handle),
                'display_name' => $cached->display_name,
                'avatar' => $cached->avatar,
                'followers' => $cached->followers,
                'source' => 'tracked',
                'url' => $cached->url,
            ];
        }

        try {
            $profile = $this->adapters->for($resolvedPlatform)->resolveProfile($raw);
        } catch (Throwable $exception) {
            Log::info('Onboarding live lookup failed', [
                'platform' => $resolvedPlatform->value,
                'input' => $raw,
                'error' => $exception->getMessage(),
            ]);

            return null;
        }

        if (! is_array($profile)) {
            return null;
        }

        $resolvedHandle = mb_strtolower(ltrim((string) ($profile['handle'] ?? $handle), '@'));

        if ($resolvedHandle === '') {
            return null;
        }

        return [
            'platform' => $resolvedPlatform->value,
            'handle' => $resolvedHandle,
            'display_name' => isset($profile['display_name']) && is_string($profile['display_name'])
                ? $profile['display_name']
                : ($profile['name'] ?? null),
            'avatar' => isset($profile['avatar']) && is_string($profile['avatar'])
                ? $profile['avatar']
                : (isset($profile['profilePicUrl']) && is_string($profile['profilePicUrl'])
                    ? $profile['profilePicUrl']
                    : null),
            'followers' => isset($profile['followers']) && is_numeric($profile['followers'])
                ? (int) $profile['followers']
                : (isset($profile['followersCount']) && is_numeric($profile['followersCount'])
                    ? (int) $profile['followersCount']
                    : null),
            'source' => 'live',
            'url' => isset($profile['url']) && is_string($profile['url']) ? $profile['url'] : null,
        ];
    }

    private function guessPlatform(string $raw): ?Platform
    {
        $lower = mb_strtolower($raw);

        return match (true) {
            str_contains($lower, 'tiktok.com') || str_contains($lower, 'tiktok') && str_contains($lower, 'http') => Platform::TikTok,
            str_contains($lower, 'youtube.com') || str_contains($lower, 'youtu.be') => Platform::Youtube,
            str_contains($lower, 'linkedin.com') => Platform::LinkedIn,
            str_contains($lower, 'facebook.com') || str_contains($lower, 'fb.com') => Platform::Facebook,
            str_contains($lower, 'instagram.com') => Platform::Instagram,
            default => null,
        };
    }

    private function extractHandle(string $raw, Platform $platform): string
    {
        $value = trim($raw);

        if (str_contains($value, '://') || str_starts_with($value, 'www.')) {
            $path = parse_url(str_starts_with($value, 'http') ? $value : 'https://'.$value, PHP_URL_PATH);
            $segments = array_values(array_filter(explode('/', (string) $path)));
            $value = $segments[0] ?? $value;

            if ($platform === Platform::LinkedIn && isset($segments[1]) && in_array($segments[0], ['in', 'company'], true)) {
                $value = $segments[1];
            }
        }

        return ltrim($value, '@');
    }
}
