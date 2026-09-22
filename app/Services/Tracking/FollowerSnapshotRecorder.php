<?php

namespace App\Services\Tracking;

use App\Models\FollowerSnapshot;
use App\Models\TrackedAccount;
use Carbon\CarbonImmutable;

class FollowerSnapshotRecorder
{
    public function recordFromAccount(TrackedAccount $account): void
    {
        if ($account->social_account_id === null || $account->followers === null) {
            return;
        }

        $this->record((int) $account->social_account_id, (int) $account->followers);
    }

    public function record(int $socialAccountId, int $followers): void
    {
        $followers = max(0, $followers);
        $hadHistory = FollowerSnapshot::query()
            ->where('social_account_id', $socialAccountId)
            ->exists();

        $day = CarbonImmutable::now()->toDateString();
        $existing = FollowerSnapshot::query()
            ->where('social_account_id', $socialAccountId)
            ->whereDate('captured_on', $day)
            ->first();

        if ($existing !== null) {
            $existing->fill(['followers' => $followers])->save();
        } else {
            FollowerSnapshot::query()->create([
                'social_account_id' => $socialAccountId,
                'captured_on' => $day,
                'followers' => $followers,
            ]);
        }

        if (! $hadHistory) {
            $this->plantBaselines($socialAccountId, $followers);
        }
    }

    /**
     * When an account only has one recorded day, plant flat week/month anchors
     * so growth reads 0 instead of null until a later refresh moves the count.
     */
    public function ensureBaselines(int $socialAccountId, int $followers): void
    {
        $distinctDays = FollowerSnapshot::query()
            ->where('social_account_id', $socialAccountId)
            ->distinct()
            ->count('captured_on');

        if ($distinctDays !== 1) {
            return;
        }

        $this->plantBaselines($socialAccountId, max(0, $followers));
    }

    private function plantBaselines(int $socialAccountId, int $followers): void
    {
        foreach ([7, 30] as $daysAgo) {
            $day = CarbonImmutable::now()->subDays($daysAgo)->toDateString();

            FollowerSnapshot::query()->firstOrCreate(
                [
                    'social_account_id' => $socialAccountId,
                    'captured_on' => $day,
                ],
                [
                    'followers' => $followers,
                ],
            );
        }
    }
}
