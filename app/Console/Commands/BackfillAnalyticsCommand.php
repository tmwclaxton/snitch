<?php

namespace App\Console\Commands;

use App\Services\SnitchAnalyticsService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('snitch:backfill-analytics')]
#[Description('Rebuild public daily analytics counters from existing posts, analyses, and winners')]
class BackfillAnalyticsCommand extends Command
{
    public function handle(SnitchAnalyticsService $analytics): int
    {
        $counts = $analytics->backfillFromDomain();

        $this->info(sprintf(
            'Backfilled analytics: %d posts, %d analyses, %d winners.',
            $counts['posts'],
            $counts['analyses'],
            $counts['winners'],
        ));

        return self::SUCCESS;
    }
}
