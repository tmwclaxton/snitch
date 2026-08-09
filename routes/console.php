<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Product sync / winners rescore are agent- or user-triggered (usage billing).
// Weekly AI blog draft (default status from config/blog.php). Spot-check then blog:publish.
Schedule::command('blog:generate --length=long')
    ->weeklyOn(1, '9:00')
    ->appendOutputTo(storage_path('logs/blog-generate.log'));
