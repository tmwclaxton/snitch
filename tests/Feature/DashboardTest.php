<?php

namespace Tests\Feature;

use App\Enums\Platform;
use App\Models\BrandProfile;
use App\Models\Post;
use App\Models\TrackedAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_the_login_page(): void
    {
        $this->get(route('dashboard'))->assertRedirect(route('login'));
    }

    public function test_authenticated_users_can_visit_the_dashboard(): void
    {
        $user = User::factory()->create();
        BrandProfile::factory()->for($user)->create();

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard')
                ->has('rivals', 0)
                ->where('kpis.status', 'empty')
                ->has('onboarding')
                ->where('legacy_non_instagram_count', 0)
            );
    }

    public function test_dashboard_lists_instagram_rivals(): void
    {
        $user = User::factory()->create();
        BrandProfile::factory()->for($user)->create();
        $account = TrackedAccount::factory()->for($user)->create([
            'platform' => Platform::Instagram,
            'handle' => 'rivalbakery',
            'followers' => 1200,
        ]);
        Post::factory()->forAccount($account)->create([
            'posted_at' => now()->subDay(),
            'metrics' => ['likes' => 10, 'comments' => 2],
        ]);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard')
                ->has('rivals', 1)
                ->where('rivals.0.handle', 'rivalbakery')
                ->where('rivals.0.id', $account->id)
                ->where('show_hidden_likes', false)
                ->has('rail.cells')
                ->loadDeferredProps('panel', fn (Assert $panel) => $panel
                    ->where('leaderboard.status', 'ok')
                    ->where('leaderboard.data.rows.0.tracked_account_id', $account->id)
                    ->has('activity.heatmap')
                    ->missing('recent_posts')
                    ->has('winners')
                )
            );
    }

    public function test_dashboard_vue_wires_feed_and_tracker_links(): void
    {
        $dashboard = file_get_contents(resource_path('js/pages/Dashboard.vue'));
        $winnerCard = file_get_contents(resource_path('js/components/dashboard/WinnerCard.vue'));

        $this->assertNotFalse($dashboard);
        $this->assertNotFalse($winnerCard);
        $this->assertStringNotContainsString('Latest posts', $dashboard);
        $this->assertStringNotContainsString('recent_posts', $dashboard);
        $this->assertStringContainsString('competitorShow.url(rival.id)', $dashboard);
        $this->assertStringContainsString('xl:grid-cols-6', $dashboard);
        $this->assertStringContainsString('performance vs usual', strtolower($dashboard));
        $this->assertStringContainsString('feedShow.url(post.id)', $winnerCard);
        $this->assertStringContainsString('competitorShow.url(props.post.tracked_account_id)', $winnerCard);
        $this->assertStringContainsString('their usual', $winnerCard);
        $this->assertStringContainsString('Open on Instagram', $winnerCard);
        $this->assertStringContainsString('aspect-[4/5]', $winnerCard);
        $this->assertStringNotContainsString('…', $winnerCard);
        $this->assertStringNotContainsString('...', $winnerCard);
        $this->assertStringContainsString('trackerIdsByHandle', $dashboard);
        $this->assertStringContainsString(':tracker-ids="trackerIdsByHandle"', $dashboard);
        $this->assertStringContainsString('weekly_brief.ideas', $dashboard);
        $this->assertStringContainsString('Post this next', $dashboard);
        $this->assertStringContainsString('break-words text-slate-800', $dashboard);
        $this->assertStringNotContainsString('truncate text-slate-800', $dashboard);
        $this->assertStringContainsString('lg:h-0 lg:min-h-full', $dashboard);
        $this->assertStringContainsString('snitch-dash-heatmap-panel', $dashboard);
        $this->assertStringNotContainsString('mt-auto p-2', $dashboard);

        $insightList = file_get_contents(resource_path('js/components/dashboard/InsightList.vue'));
        $this->assertNotFalse($insightList);
        $this->assertStringContainsString('data-tracker-id', $insightList);
        $this->assertStringContainsString('competitorShow.url(id)', $insightList);
        $this->assertStringContainsString('Show all', $insightList);
        $this->assertStringNotContainsString('line-clamp-', $insightList);

        $compare = file_get_contents(resource_path('js/components/dashboard/CompareTable.vue'));
        $this->assertNotFalse($compare);
        $this->assertStringContainsString('text-right', $compare);
        $this->assertStringContainsString('showGrowth', $compare);
        $this->assertStringContainsString('no posts yet', $compare);
        $this->assertStringContainsString('toFixed(1)', $compare);
        $this->assertStringContainsString('postTypeLabel', $compare);
        $this->assertStringNotContainsString('postTypeShortLabel', $compare);
        $this->assertStringNotContainsString('uppercase tracking-wide', $compare);

        $statCard = file_get_contents(resource_path('js/components/dashboard/StatCard.vue'));
        $this->assertNotFalse($statCard);
        $this->assertStringContainsString('growth from next week', $statCard);
        $this->assertStringNotContainsString('- growth', $statCard);

        $captions = file_get_contents(resource_path('js/components/dashboard/CaptionPanels.vue'));
        $this->assertNotFalse($captions);
        $this->assertStringContainsString('hookNeedsToggle', $captions);
        $this->assertStringContainsString('more', $captions);
    }

    public function test_dashboard_hidden_likes_query_persists_toggle(): void
    {
        $user = User::factory()->create();
        BrandProfile::factory()->for($user)->create();
        TrackedAccount::factory()->for($user)->create([
            'platform' => Platform::Instagram,
            'handle' => 'rivalbakery',
            'followers' => 1200,
        ]);

        $this->actingAs($user)
            ->get(route('dashboard', ['hidden' => '1']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard')
                ->where('show_hidden_likes', true)
            );

        $this->actingAs($user)
            ->get(route('dashboard', ['hidden' => '0']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard')
                ->where('show_hidden_likes', false)
            );
    }

    public function test_dashboard_empty_state_copy_mentions_instagram(): void
    {
        $dashboard = file_get_contents(resource_path('js/pages/Dashboard.vue'));

        $this->assertNotFalse($dashboard);
        $this->assertStringContainsString('No Instagram competitors yet', $dashboard);
        $this->assertStringContainsString('Only Instagram accounts appear here', $dashboard);
        $this->assertStringContainsString('No rivals to compare yet', $dashboard);
        $this->assertStringContainsString('legacy_non_instagram_count', $dashboard);
        $this->assertStringContainsString('Show hidden-likes posts', $dashboard);
        $this->assertStringContainsString('ranked on comments and views', $dashboard);
        $this->assertStringContainsString('hidden: !show_hidden_likes', $dashboard);
    }

    public function test_dashboard_reports_legacy_non_instagram_trackers(): void
    {
        $user = User::factory()->create();
        BrandProfile::factory()->for($user)->create();
        TrackedAccount::factory()->for($user)->create([
            'platform' => Platform::Facebook,
            'handle' => 'oldfacebookpage',
        ]);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard')
                ->has('rivals', 0)
                ->where('legacy_non_instagram_count', 1)
            );
    }

    public function test_authenticated_users_without_brand_are_sent_to_onboarding(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertRedirect(route('onboarding.show'));
    }
}
