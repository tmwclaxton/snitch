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
        $day = CarbonImmutable::now()->toDateString();
        $existing = FollowerSnapshot::query()
            ->where('social_account_id', $socialAccountId)
            ->whereDate('captured_on', $day)
            ->first();

        if ($existing !== null) {
            $existing->fill(['followers' => max(0, $followers)])->save();

            return;
        }

        FollowerSnapshot::query()->create([
            'social_account_id' => $socialAccountId,
            'captured_on' => $day,
            'followers' => max(0, $followers),
        ]);
    }
}
