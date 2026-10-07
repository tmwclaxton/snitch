<?php

namespace App\Console\Commands;

use App\Jobs\GenerateDailyBriefJob;
use App\Models\User;
use App\Services\Brief\BorrowedCompetitorNameSanitizer;
use App\Services\Brief\DailyBriefCopySanitizer;
use App\Services\Brief\DailyBriefGenerator;
use App\Services\Growth\MonthlyReportBuilder;
use App\Support\ScheduleHeartbeat;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Throwable;

class GenerateDailyBriefsCommand extends Command
{
    protected $signature = 'snitch:generate-daily-briefs
        {--user= : Limit to a single user id}
        {--date= : Brief date Y-m-d in Europe/London}
        {--sync : Run inline instead of queueing}
        {--force : Regenerate even when a brief already exists}
        {--sanitize : Re-run deterministic copy sanitizers on the stored brief without regenerating}';

    protected $description = 'Generate the daily executive summary for opted-in users';

    public function handle(
        DailyBriefGenerator $generator,
        MonthlyReportBuilder $reports,
        DailyBriefCopySanitizer $copySanitizer,
        BorrowedCompetitorNameSanitizer $borrowedNames,
    ): int {
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

        if ($this->option('sanitize')) {
            return $this->sanitizeStored($users, $generator, $copySanitizer, $borrowedNames, $date);
        }

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
                } catch (Throwable $exception) {
                    $this->warn("User {$user->id}: ".$exception->getMessage());
                }

                continue;
            }

            GenerateDailyBriefJob::queueFor((int) $user->id, $date->toDateString(), $force);
            $count++;
        }

        $this->info("Queued or generated {$count} daily briefs for {$date->toDateString()} (skipped {$skipped}).");
        $this->persistCurrentMonthlyReports($reports);

        return ScheduleHeartbeat::record('snitch:generate-daily-briefs', self::SUCCESS);
    }

    /**
     * @param  Collection<int, User>  $users
     */
    private function sanitizeStored(
        $users,
        DailyBriefGenerator $generator,
        DailyBriefCopySanitizer $copySanitizer,
        BorrowedCompetitorNameSanitizer $borrowedNames,
        CarbonImmutable $date,
    ): int {
        $count = 0;

        foreach ($users as $user) {
            $weeklyUpdated = $borrowedNames->sanitizeStoredWeeklyIdeas($user, allWeeks: true);
            $brief = $generator->briefForDate($user, $date);

            if ($brief === null) {
                $this->warn("User {$user->id}: no daily brief for {$date->toDateString()} (weekly ideas cleaned: {$weeklyUpdated}).");

                continue;
            }

            $changed = $copySanitizer->sanitizeStored($brief);
            $this->info(sprintf(
                'User %d: daily brief #%d %s (weekly ideas cleaned: %d).',
                $user->id,
                $brief->id,
                $changed ? 'sanitized' : 'already clean',
                $weeklyUpdated,
            ));
            $count++;
        }

        $this->info("Sanitized {$count} daily brief(s) for {$date->toDateString()}.");

        return ScheduleHeartbeat::record('snitch:generate-daily-briefs', self::SUCCESS);
    }

    private function persistCurrentMonthlyReports(MonthlyReportBuilder $reports): void
    {
        $monthStart = $reports->monthStart(now('Europe/London')->toDateString());
        $users = User::query()
            ->whereHas('trackedAccounts')
            ->orderBy('id')
            ->get();
        $updated = 0;

        foreach ($users as $user) {
            try {
                $reports->persist($user, $monthStart);
                $updated++;
            } catch (Throwable $exception) {
                $this->warn("Monthly report user {$user->id}: ".$exception->getMessage());
            }
        }

        $this->info("Refreshed {$updated} current-month report(s) for {$monthStart->format('Y-m')}.");
    }
}
