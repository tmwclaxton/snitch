<?php

namespace App\Console\Commands;

use App\Jobs\SyncTrackedAccountJob;
use App\Models\TrackedAccount;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('snitch:sync-accounts')]
#[Description('Enqueue sync jobs for all tracked competitor accounts')]
class SyncAccountsCommand extends Command
{
    public function handle(): int
    {
        $count = 0;

        TrackedAccount::query()
            ->orderBy('id')
            ->chunkById(100, function ($accounts) use (&$count): void {
                foreach ($accounts as $account) {
                    SyncTrackedAccountJob::dispatch($account->id);
                    $count++;
                }
            });

        $this->info("Enqueued {$count} account sync jobs.");

        return self::SUCCESS;
    }
}
