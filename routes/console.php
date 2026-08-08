<?php

use App\Jobs\ScoreWinnersJob;
use App\Models\User;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Weekly cadence; SyncAccountsCommand / SyncTrackedAccountJob also skip
// accounts successfully synced within snitch.sync.min_interval_days.
Schedule::command('snitch:sync-accounts')->weeklyOn(1, '7:00');

Schedule::call(function (): void {
    User::query()
        ->whereHas('trackedAccounts')
        ->orderBy('id')
        ->chunkById(100, function ($users): void {
            foreach ($users as $user) {
                ScoreWinnersJob::queueFor($user->id);
            }
        });
})->dailyAt('06:30')->name('snitch-score-winners');
