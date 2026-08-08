<?php

namespace Tests\Feature\Marketing;

use App\Enums\AnalysisStatus;
use App\Enums\AnalysisTermDimension;
use App\Enums\Platform;
use App\Enums\PostType;
use App\Models\AnalysisTerm;
use App\Models\Post;
use App\Models\PostAnalysis;
use App\Models\SnitchDailyPlatformStat;
use App\Models\SnitchDailyStat;
use App\Models\TrackedAccount;
use App\Models\User;
use App\Models\WinnerInsight;
use App\Services\SnitchAnalyticsService;
use App\Support\AnalyticsDateRange;
use Database\Seeders\AnalysisTermSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PublicAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    public function test_analytics_page_is_publicly_accessible(): void
    {
        SnitchDailyStat::factory()->create([
            'date' => now()->subDay()->toDateString(),
            'posts_count' => 12,
            'analyses_count' => 4,
            'winners_count' => 2,
        ]);

        SnitchDailyStat::factory()->create([
            'date' => now()->toDateString(),
            'posts_count' => 8,
            'analyses_count' => 3,
            'winners_count' => 1,
        ]);

        $expectedDays = AnalyticsDateRange::DEFAULT_DAYS;

        $this->get(route('analytics'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('marketing/Analytics')
                ->where('analytics.metrics.posts_synced.total', 20)
                ->where('analytics.metrics.posts_synced.period_total', 20)
                ->where('analytics.metrics.analyses_completed.total', 7)
                ->where('analytics.metrics.winners_scored.total', 3)
                ->where('analytics.range.days', $expectedDays)
                ->where('analytics.days', $expectedDays)
                ->has('analytics.metrics.posts_synced.series', $expectedDays)
                ->has('analytics.metrics.analyses_completed.series', $expectedDays)
                ->missing('analytics.metrics.winners_scored.series')
                ->has('analytics.platforms')
                ->has('analytics.top_terms.hook_type')
                ->has('analytics.top_terms.topic')
                ->has('analytics.top_terms.visual_craft'));
    }

    public function test_analytics_page_accepts_month_and_days_query_params(): void
    {
        $previousMonth = now()->subMonthNoOverflow()->startOfMonth();

        SnitchDailyStat::factory()->create([
            'date' => $previousMonth->copy()->addDays(2)->toDateString(),
            'posts_count' => 5,
            'analyses_count' => 1,
            'winners_count' => 1,
        ]);

        SnitchDailyStat::factory()->create([
            'date' => $previousMonth->copy()->endOfMonth()->toDateString(),
            'posts_count' => 9,
            'analyses_count' => 2,
            'winners_count' => 1,
        ]);

        SnitchDailyStat::factory()->create([
            'date' => now()->toDateString(),
            'posts_count' => 100,
            'analyses_count' => 50,
            'winners_count' => 10,
        ]);

        $this->get(route('analytics', [
            'month' => $previousMonth->format('Y-m'),
            'days' => 7,
        ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('marketing/Analytics')
                ->where('analytics.range.month', $previousMonth->format('Y-m'))
                ->where('analytics.range.days', 7)
                ->where('analytics.range.can_go_next', true)
                ->where('analytics.days', 7)
                ->where('analytics.metrics.posts_synced.period_total', 9)
                ->where('analytics.metrics.analyses_completed.period_total', 2)
                ->where('analytics.metrics.winners_scored.period_total', 1)
                ->has('analytics.metrics.posts_synced.series', 7)
                ->missing('analytics.metrics.winners_scored.series'));
    }

    public function test_analytics_page_clamps_future_month_to_current_month(): void
    {
        $futureMonth = now()->addMonthNoOverflow()->format('Y-m');

        $this->get(route('analytics', [
            'month' => $futureMonth,
            'days' => 30,
        ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('marketing/Analytics')
                ->where('analytics.range.month', now()->format('Y-m'))
                ->where('analytics.range.days', 30)
                ->where('analytics.range.can_go_next', false));
    }

    public function test_analytics_page_rejects_days_outside_allowed_range(): void
    {
        $this->get(route('analytics', ['days' => 0]))
            ->assertSessionHasErrors('days');

        $this->get(route('analytics', ['days' => AnalyticsDateRange::MAX_DAYS + 1]))
            ->assertSessionHasErrors('days');
    }

    public function test_analytics_json_is_publicly_accessible(): void
    {
        SnitchDailyStat::factory()->create([
            'date' => now()->subDay()->toDateString(),
            'posts_count' => 12,
            'analyses_count' => 4,
            'winners_count' => 2,
        ]);

        SnitchDailyStat::factory()->create([
            'date' => now()->toDateString(),
            'posts_count' => 8,
            'analyses_count' => 3,
            'winners_count' => 1,
        ]);

        $expectedDays = AnalyticsDateRange::DEFAULT_DAYS;

        $this->get(route('analytics.json'))
            ->assertOk()
            ->assertHeader('Cache-Control', 'max-age=300, public')
            ->assertJsonPath('metrics.posts_synced.total', 20)
            ->assertJsonPath('metrics.posts_synced.period_total', 20)
            ->assertJsonPath('metrics.analyses_completed.total', 7)
            ->assertJsonPath('metrics.winners_scored.total', 3)
            ->assertJsonPath('range.days', $expectedDays)
            ->assertJsonCount($expectedDays, 'metrics.posts_synced.series')
            ->assertJsonMissingPath('metrics.winners_scored.series')
            ->assertJsonMissingPath('users')
            ->assertJsonMissingPath('handles');
    }

    public function test_public_summary_fills_missing_days_with_zero(): void
    {
        SnitchDailyStat::factory()->create([
            'date' => now()->toDateString(),
            'posts_count' => 5,
            'analyses_count' => 2,
            'winners_count' => 1,
        ]);

        $summary = app(SnitchAnalyticsService::class)->publicSummary(
            AnalyticsDateRange::lastDays(7),
        );

        $this->assertSame(7, $summary['days']);
        $this->assertSame(5, $summary['metrics']['posts_synced']['period_total']);
        $this->assertSame(2, $summary['metrics']['analyses_completed']['period_total']);
        $this->assertSame(1, $summary['metrics']['winners_scored']['period_total']);
        $this->assertSame(0, $summary['metrics']['posts_synced']['series'][0]['count']);
        $this->assertSame(5, $summary['metrics']['posts_synced']['series'][6]['count']);
        $this->assertArrayNotHasKey('series', $summary['metrics']['winners_scored']);
    }

    public function test_recording_helpers_increment_daily_and_platform_stats(): void
    {
        $analytics = app(SnitchAnalyticsService::class);

        $analytics->recordPostSynced(Platform::TikTok);
        $analytics->recordPostSynced(Platform::Instagram, 2);
        $analytics->recordAnalysisCompleted();
        $analytics->recordWinnerScored(3);

        $stat = SnitchDailyStat::query()
            ->whereDate('date', now()->toDateString())
            ->first();

        $this->assertNotNull($stat);
        $this->assertSame(3, $stat->posts_count);
        $this->assertSame(1, $stat->analyses_count);
        $this->assertSame(3, $stat->winners_count);

        $this->assertSame(1, SnitchDailyPlatformStat::query()
            ->where('platform', Platform::TikTok)
            ->whereDate('date', now()->toDateString())
            ->value('posts_count'));
        $this->assertSame(2, SnitchDailyPlatformStat::query()
            ->where('platform', Platform::Instagram)
            ->whereDate('date', now()->toDateString())
            ->value('posts_count'));
    }

    public function test_public_summary_includes_platform_mix_and_top_terms(): void
    {
        $this->seed(AnalysisTermSeeder::class);

        SnitchDailyPlatformStat::factory()->create([
            'date' => now()->toDateString(),
            'platform' => Platform::TikTok,
            'posts_count' => 7,
        ]);
        SnitchDailyPlatformStat::factory()->create([
            'date' => now()->toDateString(),
            'platform' => Platform::Youtube,
            'posts_count' => 3,
        ]);

        $user = User::factory()->create();
        $account = TrackedAccount::factory()->for($user)->create();
        $post = Post::factory()->forAccount($account)->create([
            'type' => PostType::Reel,
        ]);
        $analysis = PostAnalysis::factory()->for($post)->create([
            'status' => AnalysisStatus::Completed,
            'analyzed_at' => now(),
        ]);
        $term = AnalysisTerm::query()
            ->where('dimension', AnalysisTermDimension::HookType)
            ->where('slug', 'pattern_interrupt')
            ->firstOrFail();
        $analysis->terms()->attach($term->id);

        $summary = app(SnitchAnalyticsService::class)->publicSummary(
            AnalyticsDateRange::lastDays(7),
        );

        $this->assertSame('tiktok', $summary['platforms'][0]['platform']);
        $this->assertSame(7, $summary['platforms'][0]['count']);
        $this->assertSame('youtube', $summary['platforms'][1]['platform']);
        $this->assertSame(3, $summary['platforms'][1]['count']);

        $this->assertSame('pattern_interrupt', $summary['top_terms']['hook_type'][0]['slug']);
        $this->assertSame(1, $summary['top_terms']['hook_type'][0]['count']);
        $this->assertArrayNotHasKey('caption', $summary['top_terms']['hook_type'][0]);
    }

    public function test_winner_recording_only_counts_new_insights(): void
    {
        $user = User::factory()->create();
        $account = TrackedAccount::factory()->for($user)->create();
        $post = Post::factory()->forAccount($account)->create();

        WinnerInsight::factory()->forPost($post)->create([
            'score' => 55,
        ]);

        app(SnitchAnalyticsService::class)->recordWinnerScored();

        $this->assertSame(1, (int) SnitchDailyStat::query()->sum('winners_count'));
    }
}
