<?php

namespace App\Console\Commands;

use App\Enums\Platform;
use App\Enums\TrackedAccountKind;
use App\Jobs\SyncTrackedAccountJob;
use App\Models\TrackedAccount;
use App\Models\User;
use App\Services\Billing\PlanEntitlementService;
use App\Services\Billing\UsageBillingService;
use App\Support\ScheduleHeartbeat;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('snitch:sync-accounts')]
#[Description('Enqueue Instagram sync jobs: 7-day interval for everyone, plus a light daily scrape for daily-brief users')]
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
        $postsLimit = max(1, (int) config('snitch.daily_brief.posts_limit', 6));
        $recencyDays = max(1, (int) config('snitch.daily_brief.recency_days', 30));

        TrackedAccount::query()
            ->with('user')
            ->where('platform', Platform::Instagram)
            ->orderBy('id')
            ->chunkById(100, function ($accounts) use (
                $entitlements,
                $billing,
                $postsLimit,
                $recencyDays,
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
                    $dailyBrief = $this->shouldForceDailyBriefSync($account, $user);

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

                    if (! $dailyBrief && ! $account->isDueForSync()) {
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

                    if ($dailyBrief) {
                        SyncTrackedAccountJob::dispatch(
                            $account->id,
                            force: true,
                            postsLimit: $postsLimit,
                            recencyDays: $recencyDays,
                            resolveProfile: false,
                        );
                    } else {
                        SyncTrackedAccountJob::dispatch($account->id);
                    }

                    $count++;
                }
            });

        $this->info("Enqueued {$count} account sync jobs ({$skipped} skipped recently; {$overQuota} over quota; {$billingSkipped} low balance).");

        return ScheduleHeartbeat::record('snitch:sync-accounts', self::SUCCESS);
    }

    private function shouldForceDailyBriefSync(TrackedAccount $account, User $user): bool
    {
        if (! $user->daily_brief_enabled) {
            return false;
        }

        return $account->is_own_account || $account->kind === TrackedAccountKind::Competitor;
    }
}
