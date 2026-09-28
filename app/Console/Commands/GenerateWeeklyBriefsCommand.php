<?php

namespace App\Console\Commands;

use App\Jobs\GenerateWeeklyBriefJob;
use App\Models\BrandProfile;
use App\Models\TrackedAccount;
use App\Models\User;
use App\Services\Brief\WeeklyBriefGenerator;
use Illuminate\Console\Command;

class GenerateWeeklyBriefsCommand extends Command
{
    protected $signature = 'snitch:generate-weekly-briefs {--sync : Run inline instead of queueing}';

    protected $description = 'Generate this week\'s Post this next brief for users who do not have one yet';

    public function handle(WeeklyBriefGenerator $generator): int
    {
        $weekStart = $generator->currentWeekStart();
        $userIds = TrackedAccount::query()
            ->select('user_id')
            ->distinct()
            ->pluck('user_id');

        $count = 0;

        foreach ($userIds as $userId) {
            $user = User::query()->find($userId);

            if ($user === null) {
                continue;
            }

            if (! BrandProfile::query()->where('user_id', $user->id)->exists()) {
                continue;
            }

            if ($generator->briefForWeek($user, $weekStart) !== null) {
                continue;
            }

            if ($this->option('sync')) {
                try {
                    $generator->generate($user, force: false);
                    $count++;
                } catch (\Throwable $e) {
                    $this->warn("User {$user->id}: ".$e->getMessage());
                }

                continue;
            }

            GenerateWeeklyBriefJob::queueFor((int) $user->id, force: false);
            $count++;
        }

        $this->info("Queued or generated {$count} weekly briefs for week {$weekStart->toDateString()}.");

        return self::SUCCESS;
    }
}
