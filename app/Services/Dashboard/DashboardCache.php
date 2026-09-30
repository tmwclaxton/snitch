<?php

namespace App\Services\Dashboard;

use App\Models\User;
use Illuminate\Support\Facades\Cache;

class DashboardCache
{
    public const TTL_SECONDS = 12 * 60 * 60;

    /** Bump when cached payload shape or executive headline inputs change. */
    public const KEY_VERSION = 'v2';

    public function key(User $user, string $period, array $selectedHandles, bool $showHiddenLikes = false): string
    {
        $handles = collect($selectedHandles)
            ->map(fn (string $handle): string => strtolower(ltrim(trim($handle), '@')))
            ->filter()
            ->unique()
            ->sort()
            ->values()
            ->implode(',');

        $hidden = $showHiddenLikes ? 'h1' : 'h0';

        return 'dashboard:metrics:'.self::KEY_VERSION.":{$user->id}:{$period}:{$handles}:{$hidden}";
    }

    public function forgetForUser(User|int $user): void
    {
        $userId = $user instanceof User ? $user->id : $user;

        // Array/file stores cannot wildcard-delete. Tag-style prefix list is kept
        // in a companion key so we can bust every period/selection variant.
        $indexKey = $this->indexKey($userId);
        $keys = Cache::get($indexKey, []);

        if (is_array($keys)) {
            foreach ($keys as $key) {
                Cache::forget((string) $key);
            }
        }

        Cache::forget($indexKey);
    }

    /**
     * @param  callable(): array<string, mixed>  $callback
     * @return array<string, mixed>
     */
    public function remember(User $user, string $period, array $selectedHandles, callable $callback, bool $showHiddenLikes = false): array
    {
        $key = $this->key($user, $period, $selectedHandles, $showHiddenLikes);
        $this->trackKey($user->id, $key);

        return Cache::remember($key, self::TTL_SECONDS, $callback);
    }

    private function indexKey(int $userId): string
    {
        return "dashboard:metrics:index:{$userId}";
    }

    private function trackKey(int $userId, string $key): void
    {
        $indexKey = $this->indexKey($userId);
        $keys = Cache::get($indexKey, []);

        if (! is_array($keys)) {
            $keys = [];
        }

        if (! in_array($key, $keys, true)) {
            $keys[] = $key;
            Cache::put($indexKey, $keys, self::TTL_SECONDS);
        }
    }
}
