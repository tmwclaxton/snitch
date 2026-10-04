<?php

namespace App\Console\Commands;

use App\Enums\Platform;
use App\Enums\TrackedAccountKind;
use App\Jobs\RefreshFollowerCountJob;
use App\Jobs\SyncTrackedAccountJob;
use App\Models\TrackedAccount;
use App\Models\User;
use App\Services\Billing\UsageBillingService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class DailyRefreshCommand extends Command
{
    protected $signature = 'snitch:daily-refresh
        {--user= : Limit to a single user id}';

    protected $description = 'Opt-in daily Instagram refresh (light posts + follower snapshot) for daily-brief users';

    public function handle(UsageBillingService $billing): int
    {
        $userFilter = $this->option('user');
        $postsLimit = max(1, (int) config('snitch.daily_brief.posts_limit', 6));
        $recencyDays = max(1, (int) config('snitch.daily_brief.recency_days', 30));

        $users = User::query()
            ->where('daily_brief_enabled', true)
            ->when(is_numeric($userFilter), fn ($query) => $query->where('id', (int) $userFilter))
            ->orderBy('id')
            ->get();

        $syncs = 0;
        $followers = 0;
        $skipped = 0;

        foreach ($users as $user) {
            if (! $billing->canRun($user)) {
                $skipped++;
                Log::info('snitch:daily-refresh skipped user; canRun failed', [
                    'user_id' => $user->id,
                ]);
                $this->warn("User {$user->id}: skipped (billing gate).");

                continue;
            }

            $accounts = TrackedAccount::query()
                ->where('user_id', $user->id)
                ->where('platform', Platform::Instagram)
                ->where(function ($query): void {
                    $query->where('is_own_account', true)
                        ->orWhere('kind', TrackedAccountKind::Competitor);
                })
                ->orderBy('id')
                ->get();

            $socialIds = [];

            foreach ($accounts as $account) {
                $account->markSyncRunning();
                SyncTrackedAccountJob::dispatch(
                    $account->id,
                    force: true,
                    postsLimit: $postsLimit,
                    recencyDays: $recencyDays,
                    resolveProfile: false,
                );
                $syncs++;

                if ($account->social_account_id !== null) {
                    $socialIds[(int) $account->social_account_id] = true;
                }
            }

            foreach (array_keys($socialIds) as $socialId) {
                RefreshFollowerCountJob::dispatch((int) $socialId, force: true);
                $followers++;
            }
        }

        $this->info("Enqueued {$syncs} daily account syncs and {$followers} follower refreshes ({$skipped} users skipped).");

        return self::SUCCESS;
    }
}
