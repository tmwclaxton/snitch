<?php

namespace App\Console\Commands;

use App\Jobs\GenerateWeeklyBriefJob;
use App\Models\BrandProfile;
use App\Models\TrackedAccount;
use App\Models\User;
use App\Services\Brief\WeeklyBriefGenerator;
use App\Support\ScheduleHeartbeat;
use Illuminate\Console\Command;

class GenerateWeeklyBriefsCommand extends Command
{
    protected $signature = 'snitch:generate-weekly-briefs
        {--user= : Limit to a single user id}
        {--sync : Run inline instead of queueing}
        {--force : Regenerate even when a brief already exists (admin / ops)}
        {--billable : Charge credits on --force regenerate}';

    protected $description = 'Auto-generate this week\'s Post this next brief when enough competitor data exists';

    public function handle(WeeklyBriefGenerator $generator): int
    {
        $weekStart = $generator->currentWeekStart();
        $force = (bool) $this->option('force');
        $billable = (bool) $this->option('billable');
        $userFilter = $this->option('user');

        $userIds = TrackedAccount::query()
            ->select('user_id')
            ->when(is_numeric($userFilter), fn ($q) => $q->where('user_id', (int) $userFilter))
            ->distinct()
            ->pluck('user_id');

        $count = 0;
        $skipped = 0;

        foreach ($userIds as $userId) {
            $user = User::query()->find($userId);

            if ($user === null) {
                continue;
            }

            if (! BrandProfile::query()->where('user_id', $user->id)->exists()) {
                $skipped++;

                continue;
            }

            if (! $force && $generator->briefForWeek($user, $weekStart) !== null) {
                $skipped++;

                continue;
            }

            if (! $force && ! $generator->hasEnoughData($user)) {
                $skipped++;

                continue;
            }

            if ($this->option('sync')) {
                try {
                    $brief = $generator->generate($user, force: $force, billable: $billable && $force);
                    $this->info("User {$user->id}: brief #{$brief->id} with {$brief->ideas->count()} ideas.");
                    $count++;
                } catch (\Throwable $e) {
                    $this->warn("User {$user->id}: ".$e->getMessage());
                }

                continue;
            }

            if ($force) {
                GenerateWeeklyBriefJob::queueFor((int) $user->id, force: true, billable: $billable);
            } else {
                $generator->queueIfReady($user);
            }
            $count++;
        }

        $this->info("Queued or generated {$count} weekly briefs for week {$weekStart->toDateString()} (skipped {$skipped}).");

        return ScheduleHeartbeat::record('snitch:generate-weekly-briefs', self::SUCCESS);
    }
}
