<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Weekly AI blog draft (default status from config/blog.php). Spot-check then blog:publish.
Schedule::command('blog:generate --length=long')
    ->weeklyOn(1, '9:00')
    ->appendOutputTo(storage_path('logs/blog-generate.log'));

// Current follower count only. Does not import posts. Skips accounts nobody tracks.
Schedule::command('snitch:refresh-followers')
    ->weeklyOn(1, '6:00')
    ->withoutOverlapping()
    ->onOneServer();

// Lovable core: weekly Instagram post refresh for accounts past the min interval.
Schedule::command('snitch:sync-accounts')
    ->weeklyOn(1, '7:00')
    ->withoutOverlapping()
    ->onOneServer();

// After Monday sync: free weekly "Post this next" brief when missing.
Schedule::command('snitch:generate-weekly-briefs')
    ->weeklyOn(1, '8:00')
    ->withoutOverlapping()
    ->onOneServer();

// Opt-in daily light refresh for daily-brief users (own + competitors).
Schedule::command('snitch:daily-refresh')
    ->dailyAt((string) config('snitch.daily_brief.refresh_time', '06:00'))
    ->timezone('Europe/London')
    ->withoutOverlapping()
    ->onOneServer();

// Daily executive summary after the light refresh.
Schedule::command('snitch:generate-daily-briefs')
    ->dailyAt((string) config('snitch.daily_brief.generate_time', '07:00'))
    ->timezone('Europe/London')
    ->withoutOverlapping()
    ->onOneServer();
