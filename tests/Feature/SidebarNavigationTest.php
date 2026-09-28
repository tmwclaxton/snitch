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
        foreach (['Dashboard', 'Brand', 'Competitors', 'Feed', 'Winners', 'This week', 'Explore'] as $title) {
            $this->assertStringContainsString("title: '{$title}'", $sidebar);
        }

        $this->assertStringNotContainsString("title: 'Ad Library'", $sidebar);
        $this->assertStringNotContainsString("title: 'Influencers'", $sidebar);
        $this->assertStringNotContainsString("title: 'Backlog'", $sidebar);
        $this->assertStringContainsString('isCurrentOrParentUrl', file_get_contents(
            resource_path('js/components/NavMain.vue'),
        ) ?: '');
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
            'winners.index',
            'brief.index',
            'explore.index',
        ];

        foreach ([$empty, $populated] as $user) {
            foreach ($routes as $route) {
                $this->actingAs($user)
                    ->get(route($route))
                    ->assertOk();
            }

            // Ad Library stays behind config('features.ad_library'); default off → dashboard.
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
