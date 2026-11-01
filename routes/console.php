<?php

use App\Support\ScheduleHeartbeat;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Scheduler liveness for snitch:audit. Proves schedule:work is alive in the live slot.
Schedule::call(fn () => ScheduleHeartbeat::mark(ScheduleHeartbeat::TICK))
    ->name('snitch:scheduler-heartbeat')
    ->everyFiveMinutes()
    ->onOneServer();

// Weekly AI blog draft (default status from config/blog.php). Spot-check then blog:publish.
Schedule::command('blog:generate --length=long')
    ->weeklyOn(1, '9:00')
    ->appendOutputTo(storage_path('logs/blog-generate.log'));

// One UK morning pipeline (BST = UTC+1). Do not add a second scrape.
// 07:00 BST / 06:00 UTC: calendar-day follower snapshots (interval 1).
// 11:00 BST / 10:00 UTC: retry accounts whose 06:00 profile fetch failed.
// 07:15 BST / 06:15 UTC: account sync (7-day gate) plus opt-in daily-brief light sync.
Schedule::command('snitch:refresh-followers')
    ->name('snitch:refresh-followers')
    ->dailyAt('6:00')
    ->withoutOverlapping()
    ->onOneServer()
    ->onSuccess(fn () => ScheduleHeartbeat::mark('snitch:refresh-followers'))
    ->onFailure(fn () => ScheduleHeartbeat::mark('snitch:refresh-followers', 'failure'));

Schedule::command('snitch:refresh-followers')
    ->name('snitch:refresh-followers-retry')
    ->dailyAt('10:00')
    ->withoutOverlapping()
    ->onOneServer()
    ->onSuccess(fn () => ScheduleHeartbeat::mark('snitch:refresh-followers'))
    ->onFailure(fn () => ScheduleHeartbeat::mark('snitch:refresh-followers', 'failure'));

Schedule::command('snitch:sync-accounts')
    ->dailyAt('6:15')
    ->withoutOverlapping()
    ->onOneServer()
    ->onSuccess(fn () => ScheduleHeartbeat::mark('snitch:sync-accounts'))
    ->onFailure(fn () => ScheduleHeartbeat::mark('snitch:sync-accounts', 'failure'));
