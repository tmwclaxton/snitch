<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\Winners\WinnerScorer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ScoreWinnersJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $userId) {}

    public function handle(WinnerScorer $scorer): void
    {
        $user = User::query()->find($this->userId);

        if ($user === null) {
            return;
        }

        $scorer->rescoreUser($user);
    }
}
