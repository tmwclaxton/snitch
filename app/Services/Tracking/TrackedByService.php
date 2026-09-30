<?php

namespace App\Services\Tracking;

use App\Enums\Platform;
use App\Models\TrackedAccount;
use App\Models\User;
use App\Support\SocialHandle;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class TrackedByService
{
    /**
     * Privacy-safe summary of how many other Snitch users track this user's own accounts.
     *
     * @return array{count: int, since: string}|null
     */
    public function forUser(User $user): ?array
    {
        $targets = $this->ownAccountTargets($user);

        if ($targets->isEmpty()) {
            return null;
        }

        $query = TrackedAccount::query()
            ->where('user_id', '!=', $user->id)
            ->where('is_own_account', false)
            ->where(function ($outer) use ($targets): void {
                foreach ($targets as $target) {
                    $outer->orWhere(function ($inner) use ($target): void {
                        $inner->where('platform', $target['platform'])
                            ->where(function ($handles) use ($target): void {
                                $handles->whereRaw('LOWER(handle) = ?', [$target['handle']])
                                    ->orWhereRaw('LOWER(handle) = ?', ['@'.$target['handle']]);
                            });
                    });
                }
            });

        $watcherIds = (clone $query)
            ->distinct()
            ->pluck('user_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        if ($watcherIds->isEmpty()) {
            return null;
        }

        // Drop soft-deleted users if the column exists later; today User has no SoftDeletes.
        $activeWatcherIds = User::query()
            ->whereIn('id', $watcherIds->all())
            ->pluck('id')
            ->map(fn ($id) => (int) $id);

        if ($activeWatcherIds->isEmpty()) {
            return null;
        }

        $since = (clone $query)
            ->whereIn('user_id', $activeWatcherIds->all())
            ->min('created_at');

        $sinceLabel = $since === null
            ? null
            : CarbonImmutable::parse($since, 'UTC')
                ->timezone('Europe/London')
                ->format('j M');

        return [
            'count' => $activeWatcherIds->count(),
            'since' => $sinceLabel ?? CarbonImmutable::now('Europe/London')->format('j M'),
        ];
    }

    /**
     * @return Collection<int, array{platform: string, handle: string}>
     */
    private function ownAccountTargets(User $user): Collection
    {
        $targets = collect();

        $handles = $user->brandProfile?->own_handles ?? [];

        if (is_array($handles)) {
            foreach ($handles as $platform => $handle) {
                $normalized = SocialHandle::normalize($handle);
                $platformValue = is_string($platform) ? strtolower(trim($platform)) : '';

                if ($normalized === null || $platformValue === '') {
                    continue;
                }

                if (Platform::tryFrom($platformValue) === null) {
                    continue;
                }

                $targets->push([
                    'platform' => $platformValue,
                    'handle' => $normalized,
                ]);
            }
        }

        $ownTrackers = TrackedAccount::query()
            ->where('user_id', $user->id)
            ->where('is_own_account', true)
            ->get(['platform', 'handle']);

        foreach ($ownTrackers as $tracker) {
            $normalized = SocialHandle::normalize($tracker->handle);
            $platformValue = $tracker->platform instanceof Platform
                ? $tracker->platform->value
                : strtolower((string) $tracker->platform);

            if ($normalized === null || $platformValue === '') {
                continue;
            }

            $targets->push([
                'platform' => $platformValue,
                'handle' => $normalized,
            ]);
        }

        return $targets
            ->unique(fn (array $row): string => $row['platform'].'|'.$row['handle'])
            ->values();
    }
}
