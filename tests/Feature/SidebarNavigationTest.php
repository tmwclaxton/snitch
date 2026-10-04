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

class SidebarNavigationTest extends TestCase
{
    use RefreshDatabase;

    public function test_sidebar_lists_core_product_pages(): void
    {
        $sidebar = file_get_contents(resource_path('js/components/AppSidebar.vue'));

        $this->assertNotFalse($sidebar);
        foreach (['Today', 'Brand', 'Competitors', 'Feed', 'This week', 'Growth', 'Monthly report', 'Explore'] as $title) {
            $this->assertStringContainsString("title: '{$title}'", $sidebar);
        }

        $this->assertStringContainsString('label="Dashboard"', $sidebar);
        $this->assertStringContainsString("title: 'What should we post?'", $sidebar);
        $this->assertStringContainsString("title: 'How are they performing?'", $sidebar);
        $this->assertStringContainsString("title: 'Are they running ads?'", $sidebar);
        $this->assertStringNotContainsString("title: 'Winners'", $sidebar);
        $this->assertStringNotContainsString("title: 'Ad Library'", $sidebar);
        $this->assertStringNotContainsString("title: 'Influencers'", $sidebar);
        $this->assertStringNotContainsString("title: 'Backlog'", $sidebar);
        $navMain = file_get_contents(resource_path('js/components/NavMain.vue')) ?: '';
        $this->assertStringContainsString('activeDashboardSection', $navMain);
        $this->assertStringContainsString('item.exact', $navMain);
        $this->assertStringContainsString('exact: true', $sidebar);
        $this->assertMatchesRegularExpression(
            "/title: 'Admin',[\\s\\S]{0,120}exact: true/",
            $sidebar,
        );
        $this->assertStringContainsString('overflow-y-auto', $sidebar);
        $this->assertStringContainsString('gap-0 overflow-y-auto', $sidebar);
        $this->assertStringContainsString('adminNavItems', $sidebar);
        $this->assertStringContainsString('label="Admin"', $sidebar);
        $this->assertStringContainsString('collapsible', $sidebar);
        $this->assertStringContainsString(':default-open="adminGroupOpen"', $sidebar);
        $this->assertStringContainsString(':default-open="dashboardGroupOpen"', $sidebar);
        $this->assertStringContainsString('accountNavItems', $sidebar);
        $this->assertStringContainsString('Billing', $sidebar);
        $this->assertStringContainsString('Settings', $sidebar);

        $logoPadPx = 8;
        $logoRowPx = 48;
        $accountPadPx = 8;
        $accountStripPx = 32;
        $accountGapPx = 8;
        $userRowPx = 48;
        $borderPx = 1;
        $rowPx = 32;
        $labelPx = 14;
        $groupTopFirstPx = 10;
        $groupTopPx = 12;
        $groupBelowPx = 4;
        $dashboardItems = 5;
        $platformItems = 8;
        $adminItems = 4;
        $groupCount = 3;
        $chrome = (($logoPadPx * 2) + $logoRowPx + $borderPx)
            + (($accountPadPx * 2) + $accountStripPx + $accountGapPx + $userRowPx + $borderPx);
        $groupChrome = $groupTopFirstPx + (($groupCount - 1) * $groupTopPx) + ($groupCount * ($groupBelowPx + $labelPx));
        $onDashboard = ($dashboardItems + $platformItems) * $rowPx;
        $onAdmin = ($platformItems + $adminItems) * $rowPx;
        $this->assertLessThanOrEqual(713, $chrome + $onDashboard + $groupChrome);
        $this->assertLessThanOrEqual(713, $chrome + $onAdmin + $groupChrome);
    }

    public function test_account_nav_sits_above_the_profile(): void
    {
        $sidebar = file_get_contents(resource_path('js/components/AppSidebar.vue'));

        $this->assertNotFalse($sidebar);

        $platform = strpos($sidebar, '<NavMain :items="mainNavItems" label="Platform" />');
        $account = strpos($sidebar, 'v-for="item in accountNavItems"');
        $admin = strpos($sidebar, 'label="Admin"');
        $footer = strpos($sidebar, '<SidebarFooter');
        $profile = strpos($sidebar, '<NavUser />');

        $this->assertNotFalse($platform);
        $this->assertNotFalse($account);
        $this->assertNotFalse($admin);
        $this->assertNotFalse($footer);
        $this->assertNotFalse($profile);
        $this->assertLessThan($footer, $platform);
        $this->assertLessThan($footer, $admin);
        $this->assertGreaterThan($footer, $account);
        $this->assertLessThan($profile, $footer);
        $this->assertStringContainsString('title: \'Explore\'', $sidebar);
        $explore = strpos($sidebar, "title: 'Explore'");
        $adminTitle = strpos($sidebar, "title: 'Admin'");
        $this->assertNotFalse($explore);
        $this->assertNotFalse($adminTitle);
        $this->assertLessThan($adminTitle, $explore);
    }

    public function test_nested_url_helper_matches_child_routes(): void
    {
        $source = file_get_contents(resource_path('js/composables/useCurrentUrl.ts'));

        $this->assertNotFalse($source);
        $this->assertStringContainsString("urlToCompare.startsWith(path.endsWith('/') ? path : `\${path}/`)", $source);
        $this->assertStringContainsString("path === '' || path === '/'", $source);
    }

    public function test_core_pages_render_for_empty_and_populated_users(): void
    {
        $empty = User::factory()->create();
        BrandProfile::factory()->for($empty)->create();

        $populated = User::factory()->create();
        BrandProfile::factory()->for($populated)->create();
        $account = TrackedAccount::factory()->for($populated)->create([
            'platform' => Platform::Instagram,
            'handle' => 'rivalbakery',
            'avatar' => 'https://cdn.instagram.com/expired.jpg',
        ]);
        Post::factory()->forAccount($account)->create([
            'posted_at' => now()->subDay(),
        ]);

        $routes = [
            'dashboard',
            'brand.edit',
            'competitors.index',
            'feed.index',
            'brief.index',
            'growth.index',
            'growth.report',
            'explore.index',
        ];

        foreach ([$empty, $populated] as $user) {
            foreach ($routes as $route) {
                $this->actingAs($user)
                    ->get(route($route))
                    ->assertOk();
            }

            $this->actingAs($user)
                ->get(route('winners.index'))
                ->assertRedirect(route('dashboard').'#performance');

            // Standalone Ad Library stays behind config('features.ad_library'); default off → dashboard.
            $this->actingAs($user)
                ->get(route('ads.index'))
                ->assertRedirect(route('dashboard'));
        }

        $this->actingAs($populated)
            ->get(route('competitors.show', $account))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('competitors/Show'));

        $post = Post::query()->where('social_account_id', $account->social_account_id)->firstOrFail();

        $this->actingAs($populated)
            ->get(route('feed.show', $post))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('feed/Show'));
    }

    public function test_tracking_index_exposes_avatar_with_social_fallback(): void
    {
        $user = User::factory()->create();
        BrandProfile::factory()->for($user)->create();
        $account = TrackedAccount::factory()->for($user)->create([
            'platform' => Platform::Instagram,
            'handle' => 'avatarcase',
            'avatar' => null,
        ]);
        $account->socialAccount?->forceFill([
            'avatar' => 'https://cdn.instagram.com/social-avatar.jpg',
        ])->save();

        $this->actingAs($user)
            ->get(route('competitors.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('competitors/Index')
                ->missing('accounts')
                ->loadDeferredProps('default', fn (Assert $deferred) => $deferred
                    ->has('accounts', 1)
                    ->where('accounts.0.avatar', 'https://cdn.instagram.com/social-avatar.jpg')
                )
            );
    }
}
