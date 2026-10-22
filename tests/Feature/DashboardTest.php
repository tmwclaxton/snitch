<?php

namespace Tests\Feature;

use App\Enums\Platform;
use App\Models\BrandProfile;
use App\Models\DashboardPanel;
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
                ->has('panels')
                ->where('panels_source', 'default')
                ->missing('featureSuggestions')
            );
    }

    public function test_dashboard_builds_default_panels_for_rivals_with_posts(): void
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
                ->where('panels_source', 'default')
                ->has('panels')
                ->missing('rivals')
                ->missing('executive')
            );
    }

    public function test_dashboard_vue_is_panel_canvas(): void
    {
        $dashboard = file_get_contents(resource_path('js/pages/Dashboard.vue'));
        $sidebar = file_get_contents(resource_path('js/components/AppSidebar.vue'));
        $postCard = file_get_contents(resource_path('js/components/dashboard/panels/PanelPostCard.vue'));

        $this->assertNotFalse($dashboard);
        $this->assertNotFalse($sidebar);
        $this->assertNotFalse($postCard);
        $this->assertStringContainsString('PanelBigNumber', $dashboard);
        $this->assertStringContainsString('PanelPostCard', $dashboard);
        $this->assertStringContainsString('PanelChart', $dashboard);
        $this->assertStringNotContainsString('TrackedBySection', $dashboard);
        $this->assertStringNotContainsString('VoteSection', $dashboard);
        $this->assertStringContainsString('Curated panels', $dashboard);
        $this->assertStringContainsString('Today', $dashboard);
        $this->assertStringNotContainsString('Your canvas', $dashboard);
        $this->assertStringContainsString('items-start', $dashboard);
        $this->assertStringContainsString('xl:grid-cols-3', $dashboard);
        $this->assertStringNotContainsString('id="what-to-post"', $dashboard);
        $this->assertStringNotContainsString('ExecStatTile', $dashboard);
        $this->assertStringContainsString("title: 'Dashboard'", $sidebar);
        $this->assertStringNotContainsString('feedShow.url', $postCard);
        $this->assertStringContainsString('captionExcerpt', $postCard);
        $this->assertStringContainsString('Show more', $postCard);
        $this->assertStringNotContainsString('line-clamp-', $dashboard);
        $this->assertStringNotContainsString('—', $dashboard);
        $this->assertStringNotContainsString('–', $dashboard);
    }

    public function test_dashboard_uses_stored_curator_panels(): void
    {
        $user = User::factory()->create();
        BrandProfile::factory()->for($user)->create();
        DashboardPanel::factory()->for($user)->create([
            'type' => DashboardPanel::TYPE_NOTE,
            'title' => 'Action',
            'payload' => ['body' => 'Review rival Reels today.'],
            'source' => 'curator',
        ]);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard')
                ->where('panels_source', 'stored')
                ->has('panels', 1)
                ->where('panels.0.payload.body', 'Review rival Reels today.')
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
