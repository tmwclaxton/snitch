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
