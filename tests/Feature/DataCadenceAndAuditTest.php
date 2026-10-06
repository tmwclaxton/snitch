<?php

namespace Tests\Feature;

use App\Enums\Platform;
use App\Jobs\RefreshFollowerCountJob;
use App\Models\BrandProfile;
use App\Models\FollowerSnapshot;
use App\Models\MonthlyReport;
use App\Models\Post;
use App\Models\TrackedAccount;
use App\Models\User;
use App\Services\Billing\PlanEntitlementService;
use App\Services\Billing\UsageBillingService;
use App\Services\Growth\MonthlyReportBuilder;
use App\Services\Tracking\FollowerCountRefresher;
use App\Support\ScheduleHeartbeat;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class DataCadenceAndAuditTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_follower_refresh_and_sync_are_checked_daily(): void
    {
        $events = collect(app(Schedule::class)->events())
            ->mapWithKeys(fn (Event $e): array => [(string) ($e->command ?? $e->description) => $e->expression]);

        $refreshTimes = collect(app(Schedule::class)->events())
            ->filter(fn (Event $event): bool => str_contains((string) ($event->command ?? $event->description), 'snitch:refresh-followers'))
            ->map(fn (Event $event): string => $event->expression)
            ->values();
        $sync = $events->first(fn ($expr, $cmd) => str_contains($cmd, 'snitch:sync-accounts'));
        $briefs = $events->first(fn ($expr, $cmd) => str_contains($cmd, 'snitch:generate-daily-briefs'));

        $this->assertContains('0 6 * * *', $refreshTimes);
        $this->assertContains('0 10 * * *', $refreshTimes);
        $this->assertSame('15 6 * * *', $sync);
        $this->assertSame('25 6 * * *', $briefs);
        $this->assertTrue($events->keys()->contains(fn ($cmd) => str_contains($cmd, 'snitch:scheduler-heartbeat')));
        $this->assertFalse($events->keys()->contains(fn ($cmd) => str_contains($cmd, 'snitch:daily-refresh')));
    }

    public function test_snapshot_from_yesterday_does_not_block_todays_refresh(): void
    {
        Queue::fake();
        $this->travelTo(CarbonImmutable::parse('2026-09-28 06:00:00'));

        // Tracker added Sunday evening (the 27 Sep production case).
        $account = TrackedAccount::factory()->create(['platform' => Platform::Instagram, 'followers' => 5000]);
        FollowerSnapshot::factory()->create([
            'social_account_id' => $account->social_account_id,
            'followers' => 5000,
            'captured_on' => '2026-09-27',
        ]);

        $this->assertSame([(int) $account->social_account_id], app(FollowerCountRefresher::class)->dueSocialAccountIds());

        FollowerSnapshot::factory()->create([
            'social_account_id' => $account->social_account_id,
            'followers' => 5010,
            'captured_on' => '2026-09-28',
        ]);

        $this->assertSame([], app(FollowerCountRefresher::class)->dueSocialAccountIds());
        $this->artisan('snitch:refresh-followers')->assertSuccessful();
        Queue::assertNotPushed(RefreshFollowerCountJob::class);
    }

    public function test_monthly_multiplier_uses_full_history_baseline(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-04 12:00:00'));
        $user = User::factory()->create();
        BrandProfile::factory()->for($user)->create();
        $own = TrackedAccount::factory()->for($user)->create([
            'platform' => Platform::Instagram, 'is_own_account' => true, 'followers' => 100,
        ]);

        foreach ([10, 12, 14, 16] as $i => $day) {
            Post::factory()->forAccount($own)->create([
                'posted_at' => CarbonImmutable::parse("2026-09-{$day} 10:00:00"),
                'metrics' => ['views' => 0, 'likes' => 10, 'comments' => 0],
            ]);
        }
        Post::factory()->forAccount($own)->create([
            'posted_at' => CarbonImmutable::parse('2026-10-01 17:00:00'),
            'metrics' => ['views' => 0, 'likes' => 20, 'comments' => 0],
        ]);

        $builder = app(MonthlyReportBuilder::class);
        $kpis = $builder->build($user, $builder->monthStart('2026-10'))['kpis'];

        // 20 interactions vs a median of 10 over prior posts = 2.0× usual (was "no data").
        $this->assertSame(2.0, (float) $kpis['avg_multiplier']['you']);
    }

    public function test_past_month_without_snapshot_reports_no_followers_instead_of_todays_count(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-04 12:00:00'));
        $user = User::factory()->create();
        BrandProfile::factory()->for($user)->create();
        $own = TrackedAccount::factory()->for($user)->create([
            'platform' => Platform::Instagram, 'is_own_account' => true, 'followers' => 98,
        ]);
        FollowerSnapshot::factory()->create([
            'social_account_id' => $own->social_account_id, 'followers' => 93, 'captured_on' => '2026-09-27',
        ]);

        $builder = app(MonthlyReportBuilder::class);
        $sep = $builder->build($user, $builder->monthStart('2026-09'));

        $this->assertSame(93, $sep['kpis']['followers']['you']);
        $this->assertNull($sep['kpis']['followers']['you_prev']);
        $this->assertNotContains('Followers down 5.1% vs last month.', $sep['what_changed']);
    }

    public function test_partial_month_posts_compare_with_same_days_last_month(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-04 12:00:00'));
        $user = User::factory()->create();
        BrandProfile::factory()->for($user)->create();
        $own = TrackedAccount::factory()->for($user)->create([
            'platform' => Platform::Instagram, 'is_own_account' => true, 'followers' => 100,
        ]);
        foreach (['2026-09-02', '2026-09-20', '2026-09-25', '2026-10-02'] as $day) {
            Post::factory()->forAccount($own)->create(['posted_at' => CarbonImmutable::parse("{$day} 10:00:00")]);
        }

        $builder = app(MonthlyReportBuilder::class);
        $report = $builder->build($user, $builder->monthStart('2026-10'));

        $this->assertSame(1, $report['kpis']['posts']['you']);
        $this->assertSame(1, $report['kpis']['posts']['you_prev']);
        $this->assertSame(0.0, (float) $report['kpis']['posts']['you_change']);
    }

    public function test_audit_command_outputs_json_and_flags_problems(): void
    {
        $user = User::factory()->create();
        BrandProfile::factory()->for($user)->create();
        $t = TrackedAccount::factory()->for($user)->create([
            'platform' => Platform::Instagram,
            'followers' => 1000,
            'last_synced_at' => now()->subDays(20),
            'last_sync_status' => 'success',
        ]);
        FollowerSnapshot::factory()->create(['social_account_id' => $t->social_account_id, 'followers' => 1000, 'captured_on' => now()->subDays(3)->toDateString()]);
        FollowerSnapshot::factory()->create(['social_account_id' => $t->social_account_id, 'followers' => 1500, 'captured_on' => now()->subDays(2)->toDateString()]);
        Post::factory()->forAccount($t)->create([
            'posted_at' => now()->addDays(2),
            'metrics' => ['views' => 0, 'likes' => 5000, 'comments' => -3],
        ]);
        ScheduleHeartbeat::mark(ScheduleHeartbeat::TICK);

        $this->artisan('snitch:audit', ['--json' => true, '--user' => [$user->id]])->assertExitCode(1);

        $exit = \Artisan::call('snitch:audit', ['--json' => true, '--user' => [$user->id]]);
        $payload = json_decode(\Artisan::output(), true);
        $status = collect($payload['checks'])->pluck('status', 'key');

        $this->assertSame(1, $exit);
        $this->assertFalse($payload['ok']);
        $this->assertSame('pass', $status['scheduler_heartbeat']);
        $this->assertSame('fail', $status['sync_freshness']);
        $this->assertSame('fail', $status['impossible_values']);
        $this->assertSame('fail', $status['follower_jumps']);
        $types = collect(collect($payload['checks'])->firstWhere('key', 'impossible_values')['details'])->pluck('type');
        $this->assertTrue($types->contains('negative_metric'));
        $this->assertTrue($types->contains('posted_in_future'));
        $this->assertTrue($types->contains('engagement_over_100pct'));
        $this->assertNotSame('fail', $status['monthly_report_consistency']);
        $this->assertNotSame('fail', $status['growth_consistency']);
        $this->assertNotSame('fail', $status['brief_freshness']);
    }

    public function test_audit_treats_low_balance_stale_sync_as_warn(): void
    {
        $user = User::factory()->withoutStarterCredit()->create();
        $this->assertFalse(app(UsageBillingService::class)->canRun($user));

        $tracker = TrackedAccount::factory()->for($user)->create([
            'platform' => Platform::Instagram,
            'handle' => 'yellowzest',
            'followers' => 1000,
            'last_synced_at' => now()->subDays(20),
            'last_sync_status' => 'success',
        ]);
        FollowerSnapshot::factory()->create([
            'social_account_id' => $tracker->social_account_id,
            'followers' => 1000,
            'captured_on' => now()->toDateString(),
        ]);
        ScheduleHeartbeat::mark(ScheduleHeartbeat::TICK);

        $this->artisan('snitch:audit', ['--json' => true, '--user' => [$user->id]])->assertSuccessful();

        \Artisan::call('snitch:audit', ['--json' => true, '--user' => [$user->id]]);
        $payload = json_decode(\Artisan::output(), true);
        $check = collect($payload['checks'])->firstWhere('key', 'sync_freshness');

        $this->assertSame('warn', $check['status'] ?? null);
        $this->assertSame('skipped: low balance', $check['details'][0]['skip_reason'] ?? null);
        $this->assertSame('skipped: low balance', $check['details'][0]['problem'] ?? null);
    }

    public function test_audit_treats_over_quota_stale_sync_as_warn(): void
    {
        $user = User::factory()->create();
        $this->assertTrue(app(UsageBillingService::class)->canRun($user));

        $stale = TrackedAccount::factory()->for($user)->create([
            'platform' => Platform::Instagram,
            'handle' => 'yellowzest',
            'followers' => 1000,
            'last_synced_at' => now()->subDays(20),
            'last_sync_status' => 'success',
        ]);
        $fresh = TrackedAccount::factory()->for($user)->create([
            'platform' => Platform::Instagram,
            'handle' => 'keptfresh',
            'followers' => 1000,
            'last_synced_at' => now()->subDay(),
            'last_sync_status' => 'success',
        ]);
        FollowerSnapshot::factory()->create([
            'social_account_id' => $stale->social_account_id,
            'followers' => 1000,
            'captured_on' => now()->toDateString(),
        ]);
        FollowerSnapshot::factory()->create([
            'social_account_id' => $fresh->social_account_id,
            'followers' => 1000,
            'captured_on' => now()->toDateString(),
        ]);
        ScheduleHeartbeat::mark(ScheduleHeartbeat::TICK);

        $this->app->bind(PlanEntitlementService::class, function () use ($fresh) {
            return new class((int) $fresh->id, app(UsageBillingService::class)) extends PlanEntitlementService
            {
                public function __construct(private int $allowedId, UsageBillingService $usage)
                {
                    parent::__construct($usage);
                }

                public function inQuotaTrackedAccountIds(User $user): array
                {
                    return [$this->allowedId];
                }
            };
        });

        $this->artisan('snitch:audit', ['--json' => true, '--user' => [$user->id]])->assertSuccessful();

        \Artisan::call('snitch:audit', ['--json' => true, '--user' => [$user->id]]);
        $payload = json_decode(\Artisan::output(), true);
        $check = collect($payload['checks'])->firstWhere('key', 'sync_freshness');
        $staleRow = collect($check['details'] ?? [])->firstWhere('tracker_id', $stale->id);
        $freshRow = collect($check['details'] ?? [])->firstWhere('tracker_id', $fresh->id);

        $this->assertSame('warn', $check['status'] ?? null);
        $this->assertSame('skipped: over quota', $staleRow['skip_reason'] ?? null);
        $this->assertNull($freshRow['skip_reason'] ?? null);
        $this->assertNull($freshRow['problem'] ?? null);
    }

    public function test_audit_treats_low_balance_missing_snapshot_as_warn(): void
    {
        $user = User::factory()->withoutStarterCredit()->create();
        $this->assertFalse(app(UsageBillingService::class)->canRun($user));

        TrackedAccount::factory()->for($user)->create([
            'platform' => Platform::Instagram,
            'handle' => 'samsung',
            'followers' => null,
            'last_synced_at' => null,
            'last_sync_status' => null,
        ]);
        ScheduleHeartbeat::mark(ScheduleHeartbeat::TICK);

        $this->artisan('snitch:audit', ['--json' => true, '--user' => [$user->id]])->assertSuccessful();

        \Artisan::call('snitch:audit', ['--json' => true, '--user' => [$user->id]]);
        $payload = json_decode(\Artisan::output(), true);
        $snapshot = collect($payload['checks'])->firstWhere('key', 'follower_snapshot_freshness');
        $zeroPosts = collect($payload['checks'])->firstWhere('key', 'trackers_zero_posts');

        $this->assertSame('warn', $snapshot['status'] ?? null);
        $this->assertSame('skipped: low balance', $snapshot['details'][0]['skip_reason'] ?? null);
        $this->assertSame('warn', $zeroPosts['status'] ?? null);
        $this->assertSame('skipped: low balance', $zeroPosts['details'][0]['skip_reason'] ?? null);
    }

    public function test_audit_fails_when_todays_snapshot_was_copied(): void
    {
        $user = User::factory()->create();
        $this->assertTrue(app(UsageBillingService::class)->canRun($user));

        $tracker = TrackedAccount::factory()->for($user)->create([
            'platform' => Platform::Instagram,
            'handle' => 'letsgosocialuk',
            'followers' => 97,
            'last_synced_at' => now()->subHours(2),
            'last_sync_status' => 'success',
        ]);
        FollowerSnapshot::factory()->create([
            'social_account_id' => $tracker->social_account_id,
            'followers' => 97,
            'source' => 'profile',
            'captured_on' => now()->subDay()->toDateString(),
        ]);
        FollowerSnapshot::factory()->create([
            'social_account_id' => $tracker->social_account_id,
            'followers' => 97,
            'source' => null,
            'captured_on' => now()->toDateString(),
        ]);
        ScheduleHeartbeat::mark(ScheduleHeartbeat::TICK);

        $this->artisan('snitch:audit', ['--json' => true, '--user' => [$user->id]])->assertExitCode(1);

        \Artisan::call('snitch:audit', ['--json' => true, '--user' => [$user->id]]);
        $payload = json_decode(\Artisan::output(), true);
        $snapshot = collect($payload['checks'])->firstWhere('key', 'follower_snapshot_freshness');

        $this->assertSame('fail', $snapshot['status'] ?? null);
        $this->assertSame('copied or not freshly fetched', $snapshot['details'][0]['problem'] ?? null);
    }

    public function test_scheduled_commands_record_a_heartbeat(): void
    {
        Queue::fake();
        Cache::flush();

        $this->artisan('snitch:refresh-followers', ['--queue' => true])->assertSuccessful();
        $this->artisan('snitch:sync-accounts')->assertSuccessful();
        $this->artisan('snitch:generate-daily-briefs')->assertSuccessful();
        $this->artisan('snitch:generate-weekly-briefs')->assertSuccessful();

        $this->assertNotNull(ScheduleHeartbeat::last('snitch:refresh-followers'));
        $this->assertNotNull(ScheduleHeartbeat::last('snitch:sync-accounts'));
        $this->assertNotNull(ScheduleHeartbeat::last('snitch:generate-daily-briefs'));
        $this->assertNotNull(ScheduleHeartbeat::last('snitch:generate-weekly-briefs'));
    }

    public function test_audit_infers_scheduled_job_success_when_heartbeat_cache_is_empty(): void
    {
        Cache::flush();
        $user = User::factory()->create();
        $tracker = TrackedAccount::factory()->for($user)->create([
            'platform' => Platform::Instagram,
            'followers' => 1000,
            'last_synced_at' => now()->subHour(),
            'last_sync_status' => 'success',
        ]);
        FollowerSnapshot::factory()->create([
            'social_account_id' => $tracker->social_account_id,
            'followers' => 1000,
            'captured_on' => now()->toDateString(),
            'source' => 'profile',
        ]);
        ScheduleHeartbeat::mark(ScheduleHeartbeat::TICK);

        \Artisan::call('snitch:audit', ['--json' => true, '--user' => [$user->id]]);
        $payload = json_decode(\Artisan::output(), true);
        $check = collect($payload['checks'] ?? [])->firstWhere('key', 'scheduled_jobs');
        $details = collect($check['details'] ?? []);

        $this->assertNotSame('never recorded', $details->firstWhere('command', 'snitch:refresh-followers')['problem'] ?? null);
        $this->assertNotSame('never recorded', $details->firstWhere('command', 'snitch:sync-accounts')['problem'] ?? null);
        $this->assertNotNull($details->firstWhere('command', 'snitch:refresh-followers')['last_success'] ?? null);
        $this->assertNotNull($details->firstWhere('command', 'snitch:sync-accounts')['last_success'] ?? null);
    }

    public function test_generate_daily_briefs_persists_the_current_month_report(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-06 07:00:00', 'Europe/London'));
        $user = User::factory()->create(['daily_brief_enabled' => true]);
        BrandProfile::factory()->for($user)->create();
        TrackedAccount::factory()->for($user)->create([
            'platform' => Platform::Instagram,
            'is_own_account' => true,
            'followers' => 97,
        ]);

        $this->artisan('snitch:generate-daily-briefs')->assertSuccessful();

        $report = MonthlyReport::query()->where('user_id', $user->id)->first();
        $this->assertNotNull($report);
        $this->assertSame('2026-10', $report->payload['month'] ?? null);
    }
}
