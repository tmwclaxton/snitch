<?php

namespace App\Console\Commands;

use App\Jobs\SyncTrackedAccountJob;
use App\Models\TrackedAccount;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('snitch:sync-accounts')]
#[Description('Enqueue sync jobs for tracked accounts due for a weekly refresh')]
class SyncAccountsCommand extends Command
{
    public function handle(): int
    {
        $count = 0;
        $skipped = 0;

        TrackedAccount::query()
            ->orderBy('id')
            ->chunkById(100, function ($accounts) use (&$count, &$skipped): void {
                foreach ($accounts as $account) {
                    if (! $account->isDueForSync()) {
                        $skipped++;

                        continue;
                    }

                    $account->markSyncRunning();
                    SyncTrackedAccountJob::dispatch($account->id);
                    $count++;
                }
            });

        $this->info("Enqueued {$count} account sync jobs ({$skipped} skipped; synced recently).");

        return self::SUCCESS;
    }
}
