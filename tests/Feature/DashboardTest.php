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
                ->where('show_hidden_likes', false)
                ->has('rail.cells')
                ->loadDeferredProps('panel', fn (Assert $panel) => $panel
                    ->where('leaderboard.status', 'ok')
                    ->has('activity.heatmap')
                    ->has('recent_posts')
                )
            );
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
