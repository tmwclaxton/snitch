<?php

namespace App\Console\Commands;

use App\Jobs\RefreshFollowerCountJob;
use App\Services\Tracking\FollowerCountRefresher;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('snitch:refresh-followers')]
#[Description('Queue a follower-count refresh for social accounts someone still tracks')]
class RefreshFollowersCommand extends Command
{
    public function handle(FollowerCountRefresher $refresher): int
    {
        $ids = $refresher->dueSocialAccountIds();

        foreach ($ids as $id) {
            RefreshFollowerCountJob::dispatch($id);
        }

        $this->info('Enqueued '.count($ids).' follower refreshes.');

        return self::SUCCESS;
    }
}
