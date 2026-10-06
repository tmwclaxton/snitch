<?php

namespace App\Services\Tracking;

use App\Models\FollowerSnapshot;
use App\Models\TrackedAccount;

class FollowerSnapshotRecorder
{
    public const SOURCE_PROFILE = 'profile';

    /**
     * Cached tracker.followers is not a reading. Only record() after a fresh fetch.
     */
    public function recordFromAccount(TrackedAccount $account): void
    {
        // Intentionally empty. A sync that skipped resolveProfile must not
        // copy yesterday's count into today's follower_snapshots row.
    }

    public function record(int $socialAccountId, int $followers, string $source = self::SOURCE_PROFILE): void
    {
        $followers = max(0, $followers);
        $day = now()->toDateString();
        $existing = FollowerSnapshot::query()
            ->where('social_account_id', $socialAccountId)
            ->whereDate('captured_on', $day)
            ->first();

        if ($existing !== null) {
            $existing->fill([
                'followers' => $followers,
                'source' => $source,
            ])->save();

            return;
        }

        FollowerSnapshot::query()->create([
            'social_account_id' => $socialAccountId,
            'captured_on' => $day,
            'followers' => $followers,
            'source' => $source,
        ]);
    }
}
