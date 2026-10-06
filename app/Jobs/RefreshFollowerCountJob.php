<?php

namespace App\Jobs;

use App\Models\SocialAccount;
use App\Services\Tracking\FollowerCountRefresher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class RefreshFollowerCountJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [900, 3600];

    public function __construct(
        public int $socialAccountId,
        public bool $force = false,
    ) {}

    public function handle(FollowerCountRefresher $refresher): void
    {
        $social = SocialAccount::query()->find($this->socialAccountId);

        if ($social === null) {
            return;
        }

        $refresher->refresh($social, $this->force);
    }
}
