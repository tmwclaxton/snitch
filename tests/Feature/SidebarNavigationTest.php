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

    public function test_desktop_sidebar_stretches_with_page_and_keeps_sticky_viewport_shell(): void
    {
        $sidebar = file_get_contents(resource_path('js/components/ui/sidebar/Sidebar.vue'));

        $this->assertNotFalse($sidebar);
        $this->assertStringContainsString('h-full min-h-svh', $sidebar);
        $this->assertStringContainsString('sticky top-0', $sidebar);
        $this->assertStringContainsString('h-svh', $sidebar);
        $this->assertStringNotContainsString('fixed inset-y-0', $sidebar);
        $this->assertStringNotContainsString('self-stretch', $sidebar);
    }

    public function test_sidebar_lists_simplified_product_pages(): void
    {
        $sidebar = file_get_contents(resource_path('js/components/AppSidebar.vue'));

        $this->assertNotFalse($sidebar);
        foreach (['Executive Brief', 'Chat', 'Competitors', 'Connect your AI'] as $title) {
            $this->assertStringContainsString("title: '{$title}'", $sidebar);
        }

        $this->assertStringContainsString("title: 'Automation runs'", $sidebar);

        foreach (['Today', 'Brand', 'Feed', 'This week', 'Growth', 'Monthly report', 'Explore', 'Canvas', 'Vote on features'] as $title) {
            $this->assertStringNotContainsString("title: '{$title}'", $sidebar);
        }

        $this->assertStringNotContainsString('questionNavItems', $sidebar);
        $this->assertStringContainsString('label="App"', $sidebar);
        $this->assertStringContainsString('adminNavItems', $sidebar);
        $this->assertStringContainsString('label="Admin"', $sidebar);
        $this->assertStringContainsString('accountNavItems', $sidebar);
        $this->assertStringContainsString('Billing', $sidebar);
        $this->assertStringContainsString('Settings', $sidebar);
        $this->assertMatchesRegularExpression(
            "/title: 'Admin',[\\s\\S]{0,120}exact: true/",
            $sidebar,
        );
    }

    public function test_account_nav_sits_above_the_profile(): void
    {
        $sidebar = file_get_contents(resource_path('js/components/AppSidebar.vue'));

        $this->assertNotFalse($sidebar);

        $platform = strpos($sidebar, ':items="mainNavItems"');
        $account = strpos($sidebar, 'v-for="item in accountNavItems"');
        $admin = strpos($sidebar, 'label="Admin"');
        $footer = strpos($sidebar, '<SidebarFooter');
        $profile = strpos($sidebar, '<NavUser');

        $this->assertNotFalse($platform);
        $this->assertNotFalse($account);
        $this->assertNotFalse($admin);
        $this->assertNotFalse($footer);
        $this->assertNotFalse($profile);
        $this->assertLessThan($footer, $platform);
        $this->assertLessThan($footer, $admin);
        $this->assertGreaterThan($footer, $account);
        $this->assertLessThan($profile, $footer);
    }

    public function test_nested_url_helper_matches_child_routes(): void
    {
        $source = file_get_contents(resource_path('js/composables/useCurrentUrl.ts'));

        $this->assertNotFalse($source);
        $this->assertStringContainsString("urlToCompare.startsWith(path.endsWith('/') ? path : `\${path}/`)", $source);
        $this->assertStringContainsString("path === '' || path === '/'", $source);
    }

    public function test_core_pages_and_retired_redirects(): void
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

        foreach ([$empty, $populated] as $user) {
            $this->actingAs($user)->get(route('dashboard'))->assertOk();
            $this->actingAs($user)->get(route('chat.index'))->assertOk();
            $this->actingAs($user)->get(route('competitors.index'))->assertOk();
            $this->actingAs($user)->get(route('settings.mcp.show'))->assertOk();

            foreach (['today.index', 'brief.index', 'growth.index', 'growth.report', 'explore.index', 'feed.index', 'brand.edit', 'winners.index', 'ads.index'] as $route) {
                $this->actingAs($user)
                    ->get(route($route))
                    ->assertRedirect(route('dashboard'));
            }
        }

        $this->actingAs($populated)
            ->get(route('competitors.show', $account))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('competitors/Show'));

        $post = Post::query()->where('social_account_id', $account->social_account_id)->firstOrFail();

        $this->actingAs($populated)
            ->get(route('feed.show', $post))
            ->assertRedirect(route('dashboard'));
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
