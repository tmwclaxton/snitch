<?php

namespace App\Console\Commands;

use App\Enums\Platform;
use App\Models\FollowerSnapshot;
use App\Models\MonthlyReport;
use App\Models\Post;
use App\Models\TrackedAccount;
use App\Models\User;
use App\Models\WinnerInsight;
use App\Services\Billing\PlanEntitlementService;
use App\Services\Billing\UsageBillingService;
use App\Services\Dashboard\DashboardMath;
use App\Services\Growth\GrowthMetricsBuilder;
use App\Services\Growth\MonthlyReportBuilder;
use App\Support\InstagramPostId;
use App\Support\ScheduleHeartbeat;
use App\Support\SyncOptions;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Read-only daily data audit. Never writes to the database or cache.
 *
 *   php artisan snitch:audit --json            # all users with trackers
 *   php artisan snitch:audit --json --user=1   # one user
 *
 * Exit code 0 = no "fail" checks (warnings allowed), 1 = at least one fail.
 */
#[Signature('snitch:audit {--json : Output machine-readable JSON} {--user=* : Limit per-user checks to these user ids}')]
#[Description('Read-only daily data audit: freshness, scheduler, queue, impossible values, duplicates, report maths')]
class AuditDataCommand extends Command
{
    private const EPS = 0.05;

    /** @var list<array{key: string, status: string, summary: string, details: list<mixed>}> */
    private array $results = [];

    public function handle(DashboardMath $math, PlanEntitlementService $entitlements, UsageBillingService $billing): int
    {
        $users = $this->auditedUsers();
        $trackers = $this->activeTrackers($users, $entitlements);

        $checks = [
            'scheduler_heartbeat' => fn () => $this->checkSchedulerHeartbeat(),
            'scheduled_jobs' => fn () => $this->checkScheduledJobs(),
            'queue_failed_jobs_24h' => fn () => $this->checkFailedJobs(),
            'queue_backlog' => fn () => $this->checkQueueBacklog(),
            'sync_freshness' => fn () => $this->checkSyncFreshness($users, $entitlements, $billing),
            'sync_empty_results' => fn () => $this->checkEmptySyncs($trackers),
            'follower_snapshot_freshness' => fn () => $this->checkSnapshotFreshness($trackers),
            'brief_freshness' => fn () => $this->checkBriefFreshness($users, $trackers),
            'impossible_values' => fn () => $this->checkImpossibleValues($trackers, $math),
            'duplicate_posts' => fn () => $this->checkDuplicatePosts($trackers),
            'follower_jumps' => fn () => $this->checkFollowerJumps($trackers),
            'multiplier_consistency' => fn () => $this->checkMultipliers($users, $trackers, $math),
            'monthly_report_consistency' => fn () => $this->checkMonthlyReports($users, $trackers, $math),
            'growth_consistency' => fn () => $this->checkGrowth($users, $trackers, $math),
            'missing_thumbnails' => fn () => $this->checkThumbnails($trackers),
            'trackers_zero_posts' => fn () => $this->checkZeroPosts($trackers),
            'analysis_backlog' => fn () => $this->checkAnalysisBacklog($trackers),
            'daily_stats_consistency' => fn () => $this->checkDailyStats(),
        ];

        foreach ($checks as $key => $check) {
            try {
                $check();
            } catch (Throwable $e) {
                $this->record($key, 'fail', 'Check crashed: '.$e->getMessage());
            }
        }

        $failed = collect($this->results)->where('status', 'fail')->count();
        $warned = collect($this->results)->where('status', 'warn')->count();
        $payload = [
            'ok' => $failed === 0,
            'generated_at' => now()->toIso8601String(),
            'summary' => ['fail' => $failed, 'warn' => $warned, 'checks' => count($this->results)],
            'checks' => $this->results,
        ];

        if ($this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            foreach ($this->results as $row) {
                $this->line(sprintf('[%s] %s: %s', strtoupper($row['status']), $row['key'], $row['summary']));
            }
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    // ---------------------------------------------------------------- scope

    /**
     * @return Collection<int, User>
     */
    private function auditedUsers(): Collection
    {
        $ids = array_map('intval', array_filter((array) $this->option('user')));

        return User::query()
            ->when($ids !== [], fn ($q) => $q->whereIn('id', $ids))
            ->whereHas('trackedAccounts')
            ->orderBy('id')
            ->get();
    }

    /**
     * Non-deleted, in-quota Instagram trackers (the ones the scheduler should keep fresh).
     *
     * @param  Collection<int, User>  $users
     * @return Collection<int, TrackedAccount>
     */
    private function activeTrackers(Collection $users, PlanEntitlementService $entitlements): Collection
    {
        return $users->flatMap(function (User $user) use ($entitlements): Collection {
            $inQuota = $entitlements->inQuotaTrackedAccountIds($user);

            return TrackedAccount::query()
                ->where('user_id', $user->id)
                ->where('platform', Platform::Instagram)
                ->whereIn('id', $inQuota === [] ? [0] : $inQuota)
                ->with('socialAccount')
                ->orderBy('id')
                ->get();
        })->values();
    }

    // ---------------------------------------------------------- scheduler

    private function checkSchedulerHeartbeat(): void
    {
        $last = ScheduleHeartbeat::last(ScheduleHeartbeat::TICK);

        if ($last === null) {
            $this->record('scheduler_heartbeat', 'fail', 'No scheduler heartbeat recorded. schedule:work is not running (or never ran since heartbeat shipped).');

            return;
        }

        $minutes = (int) round($last->diffInMinutes(now(), true));
        $this->record(
            'scheduler_heartbeat',
            $minutes <= 15 ? 'pass' : 'fail',
            "Last scheduler tick {$minutes} min ago (bad: > 15 min).",
            [['last_tick' => $last->toIso8601String()]],
        );
    }

    private function checkScheduledJobs(): void
    {
        $expect = [
            'snitch:refresh-followers' => 26,
            'snitch:sync-accounts' => 26,
            'snitch:generate-daily-briefs' => 26,
            'snitch:generate-weekly-briefs' => 24 * 8,
        ];
        $details = [];
        $status = 'pass';

        foreach ($expect as $name => $maxHours) {
            $ok = ScheduleHeartbeat::last($name);
            $bad = ScheduleHeartbeat::last($name, 'failure');
            $age = $ok === null ? null : round($ok->diffInHours(now(), true), 1);
            $row = [
                'command' => $name,
                'last_success' => $ok?->toIso8601String(),
                'last_failure' => $bad?->toIso8601String(),
                'age_hours' => $age,
                'max_hours' => $maxHours,
            ];

            if ($bad !== null && ($ok === null || $bad->gt($ok))) {
                $row['problem'] = 'latest run failed';
                $status = 'fail';
            } elseif ($ok === null) {
                $row['problem'] = 'never recorded';
                $status = $status === 'fail' ? 'fail' : 'warn';
            } elseif ($age > $maxHours) {
                $row['problem'] = 'overdue';
                $status = 'fail';
            }

            $details[] = $row;
        }

        $this->record('scheduled_jobs', $status, 'Last successful run of each scheduled data command (bad: overdue or failed).', $details);
    }

    // -------------------------------------------------------------- queue

    private function checkFailedJobs(): void
    {
        $table = (string) config('queue.failed.table', 'failed_jobs');

        if (! Schema::hasTable($table)) {
            $this->record('queue_failed_jobs_24h', 'skip', "No {$table} table.");

            return;
        }

        $rows = DB::table($table)
            ->where('failed_at', '>=', now()->subDay())
            ->orderByDesc('failed_at')
            ->limit(25)
            ->get(['id', 'queue', 'payload', 'failed_at', 'exception'])
            ->map(fn ($row): array => [
                'id' => $row->id,
                'queue' => $row->queue,
                'job' => data_get(json_decode((string) $row->payload, true), 'displayName'),
                'failed_at' => (string) $row->failed_at,
                'exception' => mb_substr((string) $row->exception, 0, 200),
            ])->all();

        $this->record(
            'queue_failed_jobs_24h',
            $rows === [] ? 'pass' : 'fail',
            count($rows).' failed job(s) in the last 24h (bad: > 0).',
            $rows,
        );
    }

    private function checkQueueBacklog(): void
    {
        $size = null;

        try {
            $size = Queue::size();
        } catch (Throwable $e) {
            $this->record('queue_backlog', 'warn', 'Could not read queue size: '.$e->getMessage());

            return;
        }

        $stuckSyncs = TrackedAccount::query()
            ->where('last_sync_status', 'running')
            ->where('updated_at', '<', now()->subMinutes((int) config('snitch.sync.stale_running_minutes', 180)))
            ->get(['id', 'handle', 'updated_at'])
            ->map(fn (TrackedAccount $a): array => ['tracker_id' => $a->id, 'handle' => $a->handle, 'running_since' => (string) $a->updated_at])
            ->all();

        $status = ($size > 200 || $stuckSyncs !== []) ? 'fail' : ($size > 50 ? 'warn' : 'pass');
        $this->record(
            'queue_backlog',
            $status,
            "Default queue size {$size}; ".count($stuckSyncs).' sync(s) stuck in running (bad: size > 200 or any stuck).',
            $stuckSyncs,
        );
    }

    // ---------------------------------------------------------- freshness

    /**
     * @param  Collection<int, User>  $users
     */
    private function checkSyncFreshness(Collection $users, PlanEntitlementService $entitlements, UsageBillingService $billing): void
    {
        $maxDays = max(1, (int) config('snitch.sync.min_interval_days', 7)) + 2;
        $details = [];
        $fails = 0;
        $skipped = 0;
        $canRunCache = [];
        $quotaCache = [];

        $trackers = TrackedAccount::query()
            ->whereIn('user_id', $users->pluck('id')->filter()->all() ?: [0])
            ->where('platform', Platform::Instagram)
            ->orderBy('id')
            ->get();

        foreach ($trackers as $t) {
            $user = $users->firstWhere('id', $t->user_id);
            $userId = (int) $t->user_id;

            if (! isset($canRunCache[$userId])) {
                $canRunCache[$userId] = $user instanceof User && $billing->canRun($user);
            }

            if (! isset($quotaCache[$userId])) {
                $quotaCache[$userId] = $user instanceof User
                    ? array_fill_keys($entitlements->inQuotaTrackedAccountIds($user), true)
                    : [];
            }

            $age = $t->last_synced_at === null ? null : round($t->last_synced_at->diffInHours(now(), true) / 24, 2);
            $problem = match (true) {
                $t->last_synced_at === null => 'never synced',
                $t->last_sync_status === 'failed' => 'last sync failed: '.mb_substr((string) $t->last_sync_error, 0, 120),
                $age > $maxDays => "stale (> {$maxDays} days)",
                default => null,
            };

            $skipReason = null;
            if ($problem !== null) {
                if (! ($quotaCache[$userId][$t->id] ?? false)) {
                    $skipReason = 'skipped: over quota';
                } elseif (! $canRunCache[$userId]) {
                    $skipReason = 'skipped: low balance';
                }
            }

            if ($skipReason !== null) {
                $skipped++;
            } elseif ($problem !== null) {
                $fails++;
            }

            $details[] = [
                'user_id' => $t->user_id,
                'tracker_id' => $t->id,
                'handle' => $t->handle,
                'own' => (bool) $t->is_own_account,
                'last_synced_at' => $t->last_synced_at?->toIso8601String(),
                'age_days' => $age,
                'status' => $t->last_sync_status,
                'problem' => $skipReason ?? $problem,
                'skip_reason' => $skipReason,
            ];
        }

        $status = $fails > 0 ? 'fail' : ($skipped > 0 ? 'warn' : 'pass');
        $this->record(
            'sync_freshness',
            $status,
            "{$fails} tracker(s) never synced, failed, or older than {$maxDays} days; {$skipped} skipped (low balance or over quota).",
            $details,
        );
    }

    /**
     * @param  Collection<int, TrackedAccount>  $trackers
     */
    private function checkEmptySyncs(Collection $trackers): void
    {
        $rows = $trackers->where('last_sync_status', 'empty')
            ->map(fn (TrackedAccount $t): array => ['tracker_id' => $t->id, 'handle' => $t->handle, 'error' => $t->last_sync_error])
            ->values()->all();

        $this->record('sync_empty_results', $rows === [] ? 'pass' : 'warn', count($rows).' tracker(s) whose last sync found no posts in the recency window (verify the account really is inactive).', $rows);
    }

    /**
     * @param  Collection<int, TrackedAccount>  $trackers
     */
    private function checkSnapshotFreshness(Collection $trackers): void
    {
        $maxDays = max(1, (int) config('snitch.followers.refresh_interval_days', 1)) + 1;
        $details = [];
        $bad = 0;

        foreach ($trackers->unique('social_account_id') as $t) {
            $latest = FollowerSnapshot::query()
                ->where('social_account_id', $t->social_account_id)
                ->orderByDesc('captured_on')
                ->first(['captured_on', 'followers']);
            $age = $latest?->captured_on === null ? null : (int) $latest->captured_on->startOfDay()->diffInDays(now()->startOfDay(), true);
            $problem = $latest === null ? 'no snapshot' : ($age > $maxDays ? "stale (> {$maxDays} days)" : null);
            $bad += $problem === null ? 0 : 1;
            $details[] = [
                'social_account_id' => $t->social_account_id,
                'handle' => $t->handle,
                'last_captured_on' => $latest?->captured_on?->toDateString(),
                'followers' => $latest?->followers,
                'age_days' => $age,
                'problem' => $problem,
            ];
        }

        $this->record('follower_snapshot_freshness', $bad === 0 ? 'pass' : 'fail', "{$bad} tracked account(s) with no follower snapshot in {$maxDays} days.", $details);
    }

    /**
     * @param  Collection<int, User>  $users
     * @param  Collection<int, TrackedAccount>  $trackers
     */
    private function checkBriefFreshness(Collection $users, Collection $trackers): void
    {
        $details = [];
        $status = 'pass';
        $userIds = $trackers->pluck('user_id')->unique()->values();

        foreach ($userIds as $userId) {
            $weekly = DB::table('weekly_briefs')->where('user_id', $userId)->where('status', 'ready')->max('week_start');
            $weeklyAge = $weekly === null ? null : (int) CarbonImmutable::parse($weekly)->diffInDays(now(), true);
            $row = ['user_id' => $userId, 'weekly_week_start' => $weekly, 'weekly_age_days' => $weeklyAge];

            if ($weekly === null || $weeklyAge > 8) {
                $row['problem'] = 'weekly brief missing or older than 8 days';
                $status = $status === 'fail' ? 'fail' : 'warn';
            }

            $user = $users->firstWhere('id', $userId);

            if (Schema::hasTable('daily_briefs') && $user?->daily_brief_enabled) {
                $daily = DB::table('daily_briefs')->where('user_id', $userId)->max('created_at');
                $dailyAge = $daily === null ? null : round(CarbonImmutable::parse($daily)->diffInHours(now(), true), 1);
                $row['daily_last_created_at'] = $daily;
                $row['daily_age_hours'] = $dailyAge;

                if ($daily === null || $dailyAge > 26) {
                    $row['problem'] = trim(($row['problem'] ?? '').'; daily brief missing or older than 26h', '; ');
                    $status = 'fail';
                }
            }

            $details[] = $row;
        }

        $note = Schema::hasTable('daily_briefs') ? '' : ' (daily_briefs table not deployed yet: daily check skipped)';
        $this->record('brief_freshness', $status, 'Latest weekly brief <= 8 days, daily brief <= 26h'.$note.'.', $details);
    }

    // ------------------------------------------------------- data quality

    /**
     * @param  Collection<int, TrackedAccount>  $trackers
     */
    private function checkImpossibleValues(Collection $trackers, DashboardMath $math): void
    {
        $socialIds = $trackers->pluck('social_account_id')->filter()->unique()->values()->all();
        $issues = [];

        foreach (Post::query()->whereIn('social_account_id', $socialIds ?: [0])->get() as $post) {
            foreach (['likes', 'comments', 'views', 'shares'] as $metric) {
                $value = data_get($post->metrics, $metric);

                if (is_numeric($value) && (int) $value < 0 && ! ($metric === 'likes' && (int) $value === -1)) {
                    $issues[] = ['type' => 'negative_metric', 'post_id' => $post->id, 'metric' => $metric, 'value' => (int) $value];
                }
            }

            if ($post->posted_at !== null && $post->posted_at->gt(now()->addHour())) {
                $issues[] = ['type' => 'posted_in_future', 'post_id' => $post->id, 'posted_at' => $post->posted_at->toIso8601String()];
            }

            $snapshots = $this->snapshotRows((int) $post->social_account_id);
            $followers = $math->followersAt($snapshots, $post->posted_at, null);
            $er = $math->engagementRate($post, $followers);

            if ($er !== null && $er > 100) {
                $issues[] = ['type' => 'engagement_over_100pct', 'post_id' => $post->id, 'er' => round($er, 1), 'followers' => $followers];
            }
        }

        foreach ($trackers as $t) {
            if ($t->followers !== null && (int) $t->followers <= 0) {
                $issues[] = ['type' => 'tracker_followers_zero_or_negative', 'tracker_id' => $t->id, 'handle' => $t->handle, 'followers' => $t->followers];
            }
        }

        $snapIssues = FollowerSnapshot::query()
            ->whereIn('social_account_id', $socialIds ?: [0])
            ->where('followers', '<=', 0)
            ->get(['id', 'social_account_id', 'followers', 'captured_on'])
            ->map(fn (FollowerSnapshot $s): array => ['type' => 'snapshot_followers_zero_or_negative', 'snapshot_id' => $s->id, 'social_account_id' => $s->social_account_id, 'captured_on' => $s->captured_on?->toDateString()])
            ->all();

        $issues = [...$issues, ...$snapIssues];
        $this->record('impossible_values', $issues === [] ? 'pass' : 'fail', count($issues).' impossible value(s): negative counts, ER > 100%, followers <= 0, posts dated in the future.', array_slice($issues, 0, 50));
    }

    /**
     * @param  Collection<int, TrackedAccount>  $trackers
     */
    private function checkDuplicatePosts(Collection $trackers): void
    {
        $socialIds = $trackers->pluck('social_account_id')->filter()->unique()->values()->all();
        $dupes = Post::query()
            ->whereIn('social_account_id', $socialIds ?: [0])
            ->get(['id', 'social_account_id', 'external_id', 'url'])
            ->groupBy(fn (Post $p): string => $p->social_account_id.'|'.(InstagramPostId::fromUrl((string) $p->url) ?? $p->external_id ?? $p->url))
            ->filter(fn (Collection $group): bool => $group->count() > 1)
            ->map(fn (Collection $group, string $key): array => ['key' => $key, 'post_ids' => $group->pluck('id')->all()])
            ->values()->all();

        $this->record('duplicate_posts', $dupes === [] ? 'pass' : 'fail', count($dupes).' duplicate post group(s) (same account + shortcode).', $dupes);
    }

    /**
     * @param  Collection<int, TrackedAccount>  $trackers
     */
    private function checkFollowerJumps(Collection $trackers): void
    {
        $jumps = [];

        foreach ($trackers->unique('social_account_id') as $t) {
            $rows = FollowerSnapshot::query()
                ->where('social_account_id', $t->social_account_id)
                ->where('captured_on', '>=', now()->subDays(60)->toDateString())
                ->orderBy('captured_on')
                ->get(['captured_on', 'followers'])
                ->values();

            for ($i = 1; $i < $rows->count(); $i++) {
                $prev = (int) $rows[$i - 1]->followers;
                $cur = (int) $rows[$i]->followers;
                $gap = (int) $rows[$i - 1]->captured_on->diffInDays($rows[$i]->captured_on, true);

                if ($prev > 0 && $gap <= 7 && abs($cur - $prev) / $prev > 0.20) {
                    $jumps[] = [
                        'handle' => $t->handle,
                        'from' => $rows[$i - 1]->captured_on->toDateString(),
                        'to' => $rows[$i]->captured_on->toDateString(),
                        'followers_from' => $prev,
                        'followers_to' => $cur,
                        'change_pct' => round(($cur - $prev) / $prev * 100, 1),
                    ];
                }
            }
        }

        $this->record('follower_jumps', $jumps === [] ? 'pass' : 'fail', count($jumps).' follower change(s) > 20% between consecutive snapshots <= 7 days apart.', $jumps);
    }

    // ------------------------------------------------------------ maths

    /**
     * Winner insight multipliers ("X× usual" shown on cards) vs an independent recompute
     * over the account's full post history with current metrics.
     *
     * @param  Collection<int, User>  $users
     * @param  Collection<int, TrackedAccount>  $trackers
     */
    private function checkMultipliers(Collection $users, Collection $trackers, DashboardMath $math): void
    {
        $mismatches = [];
        $checked = 0;

        foreach ($users as $user) {
            $socialIds = $trackers->where('user_id', $user->id)->pluck('social_account_id')->filter()->unique()->all();
            $insights = WinnerInsight::query()
                ->where('user_id', $user->id)
                ->whereNotNull('performance_multiplier')
                ->whereHas('post', fn ($q) => $q->whereIn('social_account_id', $socialIds ?: [0]))
                ->with('post')
                ->get();

            foreach ($insights as $insight) {
                $post = $insight->post;
                $history = $this->history((int) $post->social_account_id);
                $pi = $math->performanceIndex($post, $history)['pi'];
                $checked++;

                if ($pi === null || abs(round($pi, 2) - (float) $insight->performance_multiplier) > self::EPS) {
                    $mismatches[] = [
                        'user_id' => $user->id,
                        'winner_insight_id' => $insight->id,
                        'post_id' => $post->id,
                        'stored' => (float) $insight->performance_multiplier,
                        'recomputed' => $pi === null ? null : round($pi, 2),
                    ];
                }
            }
        }

        $this->record('multiplier_consistency', $mismatches === [] ? 'pass' : 'fail', count($mismatches)." of {$checked} stored winner multipliers differ from an independent recompute by > ".self::EPS.'.', $mismatches);
    }

    /**
     * Monthly report KPIs (MonthlyReportBuilder::build, read-only) vs raw rows, for the
     * current and previous month. Also flags a stored payload that drifted from a fresh build.
     *
     * @param  Collection<int, User>  $users
     * @param  Collection<int, TrackedAccount>  $trackers
     */
    private function checkMonthlyReports(Collection $users, Collection $trackers, DashboardMath $math): void
    {
        $builder = app(MonthlyReportBuilder::class);
        $mismatches = [];
        $drift = [];
        $now = CarbonImmutable::now(DashboardMath::TIMEZONE);

        foreach ($users as $user) {
            $mine = $trackers->where('user_id', $user->id);

            if ($mine->isEmpty()) {
                continue;
            }

            foreach ([$now->startOfMonth(), $now->startOfMonth()->subMonth()] as $monthStart) {
                $built = $builder->build($user, $monthStart)['kpis'];
                $monthEnd = $monthStart->isSameMonth($now) ? $now->endOfDay() : $monthStart->endOfMonth();
                $own = $mine->first(fn (TrackedAccount $t): bool => (bool) $t->is_own_account);
                $rivals = $mine->reject(fn (TrackedAccount $t): bool => (bool) $t->is_own_account);
                $live = $monthStart->isSameMonth($now);

                $ownRaw = $own === null ? null : $this->rawMonthStats($own, $monthStart, $monthEnd, $math, $live);
                $rivalRaw = $rivals->map(fn (TrackedAccount $t): array => $this->rawMonthStats($t, $monthStart, $monthEnd, $math, $live));
                $expected = [
                    'followers' => [$ownRaw['followers'] ?? null, $math->median($rivalRaw->pluck('followers')->filter(fn ($v) => $v !== null))],
                    'posts' => [$ownRaw['posts'] ?? 0, $rivals->isEmpty() ? null : $math->median($rivalRaw->pluck('posts'))],
                    'engagement_rate' => [$ownRaw['er'] ?? null, $math->median($rivalRaw->pluck('er')->filter(fn ($v) => $v !== null))],
                    'avg_multiplier' => [$ownRaw['pi'] ?? null, $math->median($rivalRaw->pluck('pi')->filter(fn ($v) => $v !== null))],
                ];

                foreach ($expected as $kpi => [$you, $peer]) {
                    foreach (['you' => $you, 'peer' => $peer] as $side => $want) {
                        $got = $built[$kpi][$side] ?? null;

                        if (! $this->same($got, $want)) {
                            $mismatches[] = ['user_id' => $user->id, 'month' => $monthStart->format('Y-m'), 'kpi' => $kpi, 'side' => $side, 'builder' => $got, 'raw' => $want];
                        }
                    }
                }

                $stored = MonthlyReport::query()->where('user_id', $user->id)->whereDate('month_start', $monthStart->toDateString())->first();

                if ($stored !== null && is_array($stored->payload)) {
                    foreach (['followers', 'posts', 'engagement_rate', 'avg_multiplier'] as $kpi) {
                        foreach (['you', 'peer'] as $side) {
                            $was = data_get($stored->payload, "kpis.{$kpi}.{$side}");

                            if (! $this->same($was, $built[$kpi][$side] ?? null)) {
                                $drift[] = ['user_id' => $user->id, 'month' => $monthStart->format('Y-m'), 'kpi' => $kpi, 'side' => $side, 'stored' => $was, 'fresh_build' => $built[$kpi][$side] ?? null, 'stored_at' => (string) $stored->updated_at];
                            }
                        }
                    }
                }
            }
        }

        $status = $mismatches !== [] ? 'fail' : ($drift !== [] ? 'warn' : 'pass');
        $this->record(
            'monthly_report_consistency',
            $status,
            count($mismatches).' builder-vs-raw KPI mismatch(es); '.count($drift).' stored-vs-fresh drift(s) (stored payload refreshes when the page is opened).',
            ['mismatches' => $mismatches, 'stored_drift' => $drift],
        );
    }

    /**
     * /growth summary per account (30d) vs raw rows.
     *
     * @param  Collection<int, User>  $users
     * @param  Collection<int, TrackedAccount>  $trackers
     */
    private function checkGrowth(Collection $users, Collection $trackers, DashboardMath $math): void
    {
        $builder = app(GrowthMetricsBuilder::class);
        $mismatches = [];
        $end = CarbonImmutable::now(DashboardMath::TIMEZONE)->endOfDay();
        $start = $end->subDays(30)->startOfDay();

        foreach ($users as $user) {
            if ($trackers->where('user_id', $user->id)->isEmpty()) {
                continue;
            }

            $built = $builder->build($user, '30d');

            foreach ($built['accounts'] as $row) {
                $t = $trackers->firstWhere('id', $row['tracked_account_id']);

                if ($t === null) {
                    continue;
                }

                $raw = $this->rawMonthStats($t, $start, $end, $math, true);
                $latestSnap = FollowerSnapshot::query()->where('social_account_id', $t->social_account_id)
                    ->where('captured_on', '>=', $start->toDateString())
                    ->where('captured_on', '<=', $end->toDateString())
                    ->orderByDesc('captured_on')->value('followers');
                $weeks = max(1, (int) ceil($start->diffInDays($end) / 7));
                $expected = [
                    'followers' => $latestSnap !== null ? (int) $latestSnap : ((int) $t->followers > 0 ? (int) $t->followers : null),
                    'posts_per_week' => round($raw['posts'] / $weeks, 2),
                    'engagement_rate' => $raw['er'],
                    'avg_multiplier' => $raw['pi'],
                ];

                foreach ($expected as $key => $want) {
                    $got = $row['summary'][$key] ?? null;

                    if (! $this->same($got, $want)) {
                        $mismatches[] = ['user_id' => $user->id, 'handle' => $t->handle, 'metric' => $key, 'builder' => $got, 'raw' => $want];
                    }
                }
            }
        }

        $this->record('growth_consistency', $mismatches === [] ? 'pass' : 'fail', count($mismatches).' /growth (30d) summary value(s) differ from raw rows.', $mismatches);
    }

    // ------------------------------------------------------- coverage

    /**
     * @param  Collection<int, TrackedAccount>  $trackers
     */
    private function checkThumbnails(Collection $trackers): void
    {
        $socialIds = $trackers->pluck('social_account_id')->filter()->unique()->values()->all();
        $rows = Post::query()
            ->whereIn('social_account_id', $socialIds ?: [0])
            ->where('posted_at', '>=', now()->subDays(90))
            ->where(fn ($q) => $q->whereNull('cover_url')->orWhere('cover_url', ''))
            ->get(['id', 'social_account_id', 'url', 'posted_at'])
            ->map(fn (Post $p): array => ['post_id' => $p->id, 'social_account_id' => $p->social_account_id, 'url' => $p->url])
            ->all();

        $this->record('missing_thumbnails', $rows === [] ? 'pass' : 'warn', count($rows).' post(s) from the last 90 days without a cover/thumbnail.', $rows);
    }

    /**
     * @param  Collection<int, TrackedAccount>  $trackers
     */
    private function checkZeroPosts(Collection $trackers): void
    {
        $recency = max(1, (int) config('snitch.sync.recency_days', 30));
        $rows = [];

        foreach ($trackers as $t) {
            $total = Post::query()->where('social_account_id', $t->social_account_id)->count();
            $recent = Post::query()->where('social_account_id', $t->social_account_id)->where('posted_at', '>=', now()->subDays($recency))->count();

            if ($total === 0 || $recent === 0) {
                $rows[] = ['tracker_id' => $t->id, 'handle' => $t->handle, 'posts_total' => $total, "posts_last_{$recency}d" => $recent, 'last_sync_status' => $t->last_sync_status];
            }
        }

        $this->record('trackers_zero_posts', $rows === [] ? 'pass' : 'warn', count($rows)." tracker(s) with zero posts overall or in the last {$recency} days.", $rows);
    }

    /**
     * @param  Collection<int, TrackedAccount>  $trackers
     */
    private function checkAnalysisBacklog(Collection $trackers): void
    {
        $socialIds = $trackers->pluck('social_account_id')->filter()->unique()->values()->all();
        $rows = Post::query()
            ->whereIn('social_account_id', $socialIds ?: [0])
            ->where('posted_at', '>=', now()->subDays(SyncOptions::analysisRecencyDays()))
            ->where('created_at', '<', now()->subHours(6))
            ->with('analysis')
            ->get()
            ->filter(fn (Post $p): bool => $p->isAnalyzable()
                && ! in_array($p->analysis?->status?->value ?? null, ['completed', 'unavailable'], true))
            ->map(fn (Post $p): array => ['post_id' => $p->id, 'status' => $p->analysis?->status?->value, 'error' => mb_substr((string) $p->analysis?->error_message, 0, 120)])
            ->values()->all();

        $this->record('analysis_backlog', $rows === [] ? 'pass' : 'warn', count($rows).' analyzable post(s) older than 6h without a completed analysis.', $rows);
    }

    private function checkDailyStats(): void
    {
        $rows = [];

        for ($i = 0; $i < 7; $i++) {
            $day = now()->subDays($i)->toDateString();
            $ingested = Post::query()->whereDate('created_at', $day)->count();
            $stat = (int) DB::table('snitch_daily_stats')->whereDate('date', $day)->value('posts_count');

            if ($ingested !== $stat) {
                $rows[] = ['date' => $day, 'posts_created' => $ingested, 'snitch_daily_stats.posts_count' => $stat];
            }
        }

        $this->record('daily_stats_consistency', $rows === [] ? 'pass' : 'warn', count($rows).' day(s) in the last 7 where posts created != snitch_daily_stats.posts_count.', $rows);
    }

    // ------------------------------------------------------------ helpers

    /**
     * Independent per-account window stats from raw rows.
     *
     * @return array{followers: int|null, posts: int, er: float|null, pi: float|null}
     */
    private function rawMonthStats(TrackedAccount $t, CarbonImmutable $start, CarbonImmutable $end, DashboardMath $math, bool $allowLiveFallback): array
    {
        $history = $this->history((int) $t->social_account_id)
            ->filter(fn (Post $p): bool => $p->posted_at->lte($end->utc()))->values();
        $inWindow = $history->filter(fn (Post $p): bool => $p->posted_at->gte($start->utc()));
        $snapshots = $this->snapshotRows((int) $t->social_account_id);
        $fallback = (int) $t->followers > 0 ? (int) $t->followers : null;
        $er = [];
        $pi = [];

        foreach ($inWindow as $p) {
            $rate = $math->engagementRate($p, $math->followersAt($snapshots, $p->posted_at, $fallback));
            if ($rate !== null) {
                $er[] = $rate;
            }

            $x = $math->performanceIndex($p, $history)['pi'];

            if ($x !== null) {
                $pi[] = $x;
            }
        }

        $inMonthSnap = $snapshots
            ->filter(fn (array $s): bool => $s['captured_on'] >= $start->toDateString() && $s['captured_on'] <= $end->toDateString())
            ->sortBy('captured_on')->last();

        return [
            'followers' => $inMonthSnap !== null ? (int) $inMonthSnap['followers'] : ($allowLiveFallback ? $fallback : null),
            'posts' => $inWindow->count(),
            'er' => $er === [] ? null : round(array_sum($er) / count($er), 2),
            'pi' => $pi === [] ? null : round(array_sum($pi) / count($pi), 2),
        ];
    }

    /** @var array<int, Collection<int, Post>> */
    private array $historyCache = [];

    /**
     * @return Collection<int, Post>
     */
    private function history(int $socialId): Collection
    {
        return $this->historyCache[$socialId] ??= Post::query()
            ->where('social_account_id', $socialId)
            ->whereNotNull('posted_at')
            ->orderByDesc('posted_at')
            ->get();
    }

    /** @var array<int, Collection<int, array{captured_on: string, followers: int}>> */
    private array $snapshotCache = [];

    /**
     * @return Collection<int, array{captured_on: string, followers: int}>
     */
    private function snapshotRows(int $socialId): Collection
    {
        return $this->snapshotCache[$socialId] ??= FollowerSnapshot::query()
            ->where('social_account_id', $socialId)
            ->orderBy('captured_on')
            ->get(['captured_on', 'followers'])
            ->map(fn (FollowerSnapshot $s): array => ['captured_on' => $s->captured_on?->toDateString() ?? '', 'followers' => (int) $s->followers])
            ->values();
    }

    private function same(mixed $a, mixed $b): bool
    {
        if ($a === null || $b === null) {
            return $a === null && $b === null;
        }

        return abs((float) $a - (float) $b) <= self::EPS;
    }

    /**
     * @param  list<mixed>|array<string, mixed>  $details
     */
    private function record(string $key, string $status, string $summary, array $details = []): void
    {
        $this->results[] = ['key' => $key, 'status' => $status, 'summary' => $summary, 'details' => $details];
    }
}
