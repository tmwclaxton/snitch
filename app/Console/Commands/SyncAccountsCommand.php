<?php

namespace App\Console\Commands;

use App\Jobs\SyncTrackedAccountJob;
use App\Models\TrackedAccount;
use App\Services\Billing\PlanEntitlementService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('snitch:sync-accounts')]
#[Description('Enqueue sync jobs for tracked accounts due for a weekly refresh')]
class SyncAccountsCommand extends Command
{
    public function handle(PlanEntitlementService $entitlements): int
    {
        $count = 0;
        $skipped = 0;
        $overQuota = 0;
        $quotaCache = [];

        TrackedAccount::query()
            ->with('user')
            ->orderBy('id')
            ->chunkById(100, function ($accounts) use (
                $entitlements,
                &$count,
                &$skipped,
                &$overQuota,
                &$quotaCache,
            ): void {
                foreach ($accounts as $account) {
                    $user = $account->user;

                    if ($user === null) {
                        $skipped++;

                        continue;
                    }

                    $userId = (int) $user->id;

                    if (! isset($quotaCache[$userId])) {
                        $quotaCache[$userId] = array_fill_keys(
                            $entitlements->inQuotaTrackedAccountIds($user),
                            true,
                        );
                    }

                    if (! isset($quotaCache[$userId][$account->id])) {
                        $overQuota++;

                        continue;
                    }

                    if (! $account->isDueForSync()) {
                        $skipped++;

                        continue;
                    }

                    $account->markSyncRunning();
                    SyncTrackedAccountJob::dispatch($account->id);
                    $count++;
                }
            });

        $this->info("Enqueued {$count} account sync jobs ({$skipped} skipped recently; {$overQuota} over quota).");

        return self::SUCCESS;
    }
}
