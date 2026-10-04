<?php

namespace App\Console\Commands;

use App\Jobs\GenerateDailyBriefJob;
use App\Models\User;
use App\Services\Brief\DailyBriefGenerator;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class GenerateDailyBriefsCommand extends Command
{
    protected $signature = 'snitch:generate-daily-briefs
        {--user= : Limit to a single user id}
        {--date= : Brief date Y-m-d in Europe/London}
        {--sync : Run inline instead of queueing}
        {--force : Regenerate even when a brief already exists}';

    protected $description = 'Generate the daily executive summary for opted-in users';

    public function handle(DailyBriefGenerator $generator): int
    {
        $force = (bool) $this->option('force');
        $userFilter = $this->option('user');
        $dateOption = $this->option('date');
        $date = is_string($dateOption) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateOption) === 1
            ? CarbonImmutable::parse($dateOption, 'Europe/London')->startOfDay()
            : $generator->briefDate();

        $users = User::query()
            ->where('daily_brief_enabled', true)
            ->when(is_numeric($userFilter), fn ($query) => $query->where('id', (int) $userFilter))
            ->orderBy('id')
            ->get();

        $count = 0;
        $skipped = 0;

        foreach ($users as $user) {
            $existing = $generator->briefForDate($user, $date);

            if ($existing !== null && $existing->status === 'ready' && ! $force) {
                $skipped++;

                continue;
            }

            if ($this->option('sync')) {
                try {
                    $brief = $generator->generate($user, $date, $force);
                    $this->info("User {$user->id}: daily brief #{$brief->id} for {$date->toDateString()}.");
                    $count++;
                } catch (\Throwable $exception) {
                    $this->warn("User {$user->id}: ".$exception->getMessage());
                }

                continue;
            }

            GenerateDailyBriefJob::queueFor((int) $user->id, $date->toDateString(), $force);
            $count++;
        }

        $this->info("Queued or generated {$count} daily briefs for {$date->toDateString()} (skipped {$skipped}).");

        return self::SUCCESS;
    }
}
