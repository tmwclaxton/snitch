<?php

namespace App\Services\Tracking;

use App\Enums\TrackedAccountKind;
use App\Models\TrackedAccount;
use App\Models\User;
use App\Support\SocialHandle;

class WatchingYouService
{
    /**
     * @return array{handle: string|null, watched: bool, watcher_count: int}
     */
    public function lookup(?string $handle, ?int $exceptUserId = null): array
    {
        $normalized = SocialHandle::normalize($handle);

        if ($normalized === null) {
            return [
                'handle' => null,
                'watched' => false,
                'watcher_count' => 0,
            ];
        }

        $query = TrackedAccount::query()
            ->where('kind', TrackedAccountKind::Competitor)
            ->where(function ($inner) use ($normalized): void {
                $inner->whereRaw('LOWER(handle) = ?', [$normalized])
                    ->orWhereRaw('LOWER(handle) = ?', ['@'.$normalized]);
            });

        if ($exceptUserId !== null) {
            $query->where('user_id', '!=', $exceptUserId);
        }

        $watcherCount = (int) $query->distinct()->count('user_id');

        return [
            'handle' => $normalized,
            'watched' => $watcherCount > 0,
            'watcher_count' => $watcherCount,
        ];
    }

    /**
     * @return list<array{handle: string|null, watched: bool, watcher_count: int, platform: string}>
     */
    public function forBrandHandles(User $user): array
    {
        $handles = $user->brandProfile?->own_handles ?? [];

        if (! is_array($handles)) {
            return [];
        }

        $results = [];

        foreach ($handles as $platform => $handle) {
            if (! is_string($handle) || trim($handle) === '') {
                continue;
            }

            $results[] = [
                ...$this->lookup($handle, $user->id),
                'platform' => is_string($platform) ? $platform : '',
            ];
        }

        return $results;
    }
}
