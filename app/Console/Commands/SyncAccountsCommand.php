<?php

namespace App\Console\Commands;

use App\Jobs\SyncTrackedAccountJob;
use App\Models\TrackedAccount;
use App\Services\Billing\PlanEntitlementService;
use App\Services\Billing\UsageBillingService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('snitch:sync-accounts')]
#[Description('Ops only: enqueue sync jobs for tracked accounts past the min sync interval (not scheduled for users)')]
class SyncAccountsCommand extends Command
{
    public function handle(PlanEntitlementService $entitlements, UsageBillingService $billing): int
    {
        $count = 0;
        $skipped = 0;
        $overQuota = 0;
        $billingSkipped = 0;
        $quotaCache = [];
        $billingCache = [];

        TrackedAccount::query()
            ->with('user')
            ->orderBy('id')
            ->chunkById(100, function ($accounts) use (
                $entitlements,
                $billing,
                &$count,
                &$skipped,
                &$overQuota,
                &$billingSkipped,
                &$quotaCache,
                &$billingCache,
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

                    if (! isset($billingCache[$userId])) {
                        $billingCache[$userId] = $billing->canRun($user);
                    }

                    if (! $billingCache[$userId]) {
                        $billingSkipped++;

                        continue;
                    }

                    $account->markSyncRunning();
                    SyncTrackedAccountJob::dispatch($account->id);
                    $count++;
                }
            });

        $this->info("Enqueued {$count} account sync jobs ({$skipped} skipped recently; {$overQuota} over quota; {$billingSkipped} low balance).");

        return self::SUCCESS;
    }
}
