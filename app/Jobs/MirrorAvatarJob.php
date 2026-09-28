<?php

namespace App\Jobs;

use App\Models\TrackedAccount;
use App\Services\Tracking\AvatarMirror;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class MirrorAvatarJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public function __construct(public int $trackedAccountId) {}

    public function handle(AvatarMirror $mirror): void
    {
        $account = TrackedAccount::query()->with('socialAccount')->find($this->trackedAccountId);

        if ($account === null) {
            return;
        }

        try {
            $mirror->apply($account, $account->socialAccount, null);
        } catch (Throwable $e) {
            Log::warning('MirrorAvatarJob failed', [
                'tracked_account_id' => $this->trackedAccountId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
