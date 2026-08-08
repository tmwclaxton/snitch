<?php

namespace Tests\Feature;

use App\Enums\AnalysisStatus;
use App\Enums\Platform;
use App\Models\BrandProfile;
use App\Models\Post;
use App\Models\PostAnalysis;
use App\Models\TrackedAccount;
use App\Models\User;
use App\Models\WinnerInsight;
use App\Services\Dashboard\DashboardActivityBuilder;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_the_login_page(): void
    {
        $response = $this->get(route('dashboard'));

        $response->assertRedirect(route('login'));
    }

    public function test_authenticated_users_can_visit_the_dashboard(): void
    {
        $user = User::factory()->create();
        BrandProfile::factory()->for($user)->create();

        $response = $this
            ->actingAs($user)
            ->get(route('dashboard'));

        $response->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard')
                ->where('stats.tracked_accounts', 0)
                ->where('stats.posts', 0)
                ->where('stats.winners', 0)
                ->where('stats.analysis_backlog', 0)
                ->has('recent_posts', 0)
                ->has('top_winners', 0)
                ->has('activity.heatmap', DashboardActivityBuilder::HEATMAP_WEEKS * 7)
                ->has('activity.weekly', DashboardActivityBuilder::WEEKLY_WEEKS)
                ->has('activity.by_platform', 0)
                ->has('activity.by_time_of_day', 24)
                ->where('activity.heatmap.0.count', 0)
                ->where('activity.weekly.0.count', 0)
                ->where('activity.by_time_of_day.0.hour', 0)
                ->where('activity.by_time_of_day.0.label', '12a')
                ->where('activity.by_time_of_day.0.count', 0)
            );
    }

    public function test_dashboard_surfaces_live_counts_recent_posts_and_winners(): void
    {
        $user = User::factory()->create();
        BrandProfile::factory()->for($user)->create();
        $account = TrackedAccount::factory()->for($user)->create([
            'last_synced_at' => now()->subHour(),
            'last_sync_status' => 'success',
        ]);

        $ready = Post::factory()->forAccount($account)->create([
            'metrics' => [
                'views' => 12500,
                'likes' => 840,
                'comments' => 12,
                'shares' => 4,
            ],
        ]);
        PostAnalysis::factory()->for($ready)->create([
            'status' => AnalysisStatus::Completed,
            'concept' => 'Receipt cold open',
            'hook' => 'Starts on the total',
        ]);
        WinnerInsight::factory()->forPost($ready)->create([
            'score' => 77.0,
            'why' => 'Proof lands early',
        ]);

        $pending = Post::factory()->forAccount($account)->create();
        PostAnalysis::factory()->for($pending)->pending()->create();

        Post::factory()->forAccount($account)->create();

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard')
                ->where('stats.tracked_accounts', 1)
                ->where('stats.posts', 3)
                ->where('stats.winners', 1)
                ->where('stats.analysis_backlog', 2)
                ->has('recent_posts', 3)
                ->has('top_winners', 1)
                ->where('top_winners.0.post.id', $ready->id)
                ->where('top_winners.0.score', 77)
                ->where('top_winners.0.post.metrics.views', 12500)
                ->where('top_winners.0.post.metrics.likes', 840)
                ->where('top_winners.0.post.analysis.hook', 'Starts on the total')
                ->where('top_winners.0.post.analysis.concept', 'Receipt cold open')
                ->has('top_winners.0.post.embed')
                ->has('activity.heatmap')
                ->has('activity.weekly', DashboardActivityBuilder::WEEKLY_WEEKS)
            );
    }

    public function test_dashboard_activity_aggregates_posting_cadence(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-05 12:00:00'));

        try {
            $user = User::factory()->create();
            BrandProfile::factory()->for($user)->create();
            $instagram = TrackedAccount::factory()->for($user)->create([
                'platform' => Platform::Instagram,
            ]);
            $tiktok = TrackedAccount::factory()->for($user)->create([
                'platform' => Platform::TikTok,
            ]);

            Post::factory()->forAccount($instagram)->create([
                'posted_at' => CarbonImmutable::parse('2026-08-05 09:00:00'),
            ]);
            Post::factory()->forAccount($instagram)->create([
                'posted_at' => CarbonImmutable::parse('2026-08-05 18:00:00'),
            ]);
            Post::factory()->forAccount($tiktok)->create([
                'posted_at' => CarbonImmutable::parse('2026-07-27 11:00:00'),
            ]);
            Post::factory()->forAccount($tiktok)->create([
                'posted_at' => CarbonImmutable::parse('2026-04-01 11:00:00'),
            ]);

            $this->actingAs($user)
                ->get(route('dashboard'))
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->component('Dashboard')
                    ->has('activity.heatmap', DashboardActivityBuilder::HEATMAP_WEEKS * 7)
                    ->where('activity.heatmap.0.date', '2026-04-19')
                    ->where('activity.weekly.0.week_start', '2026-05-17')
                    ->where('activity.by_platform', [
                        ['platform' => 'instagram', 'count' => 2],
                        ['platform' => 'tiktok', 'count' => 1],
                    ])
                    ->has('activity.by_time_of_day', 24)
                    ->where('activity.heatmap', function ($heatmap): bool {
                        $byDate = collect($heatmap)->keyBy('date');

                        return ($byDate['2026-08-05']['count'] ?? null) === 2
                            && ($byDate['2026-07-27']['count'] ?? null) === 1
                            && ($byDate['2026-04-01']['count'] ?? null) === null;
                    })
                    ->where('activity.weekly', function ($weekly): bool {
                        $byWeek = collect($weekly)->keyBy('week_start');

                        return ($byWeek['2026-08-02']['count'] ?? null) === 2
                            && ($byWeek['2026-07-26']['count'] ?? null) === 1;
                    })
                    ->where('activity.by_time_of_day', function ($hours): bool {
                        $byHour = collect($hours)->keyBy('hour');

                        return ($byHour[9]['count'] ?? null) === 1
                            && ($byHour[11]['count'] ?? null) === 1
                            && ($byHour[18]['count'] ?? null) === 1
                            && ($byHour[0]['count'] ?? null) === 0
                            && ($byHour[9]['label'] ?? null) === '9a'
                            && ($byHour[18]['label'] ?? null) === '6p';
                    })
                );
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_authenticated_users_without_brand_are_sent_to_onboarding(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertRedirect(route('onboarding.show'));
    }
}
