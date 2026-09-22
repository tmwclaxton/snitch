<?php

namespace Tests\Feature;

use App\Enums\Platform;
use App\Models\BrandProfile;
use App\Models\SocialAd;
use App\Models\TrackedAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AdsPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_from_ads(): void
    {
        $this->get(route('ads.index'))
            ->assertRedirect(route('login'));
    }

    public function test_authenticated_users_can_visit_ads_with_deferred_catalogue(): void
    {
        $user = User::factory()->create();
        BrandProfile::factory()->for($user)->create();
        $account = TrackedAccount::factory()->for($user)->create([
            'platform' => Platform::Instagram,
            'handle' => 'gymshark',
        ]);
        SocialAd::factory()->create([
            'social_account_id' => $account->social_account_id,
            'title' => 'Autumn set menu',
            'body' => 'Book now',
            'url' => 'https://www.facebook.com/ads/library/?id=99',
            'platform' => Platform::Instagram,
        ]);
        SocialAd::factory()->create([
            'social_account_id' => $account->social_account_id,
            'title' => 'Winter drop',
            'is_active' => false,
        ]);

        $this->actingAs($user)
            ->get(route('ads.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('ad-library/Index')
                ->where('total', 1)
                ->missing('ads')
                ->loadDeferredProps('ads', fn (Assert $page) => $page
                    ->has('ads', 1)
                    ->where('ads.0.title', 'Autumn set menu')
                    ->where('ads.0.tracked_account.handle', 'gymshark')
                    ->where('ads.0.platform', 'instagram')
                )
            );
    }

    public function test_ads_page_avoids_adblock_sensitive_path(): void
    {
        $this->assertSame('/ad-library', parse_url(route('ads.index'), PHP_URL_PATH));

        $this->get('/ads')->assertRedirect('/ad-library');

        $this->assertFileExists(resource_path('js/pages/ad-library/Index.vue'));
        $this->assertFileDoesNotExist(resource_path('js/pages/ads/Index.vue'));
    }

    public function test_dashboard_ads_overview_links_to_ads_page(): void
    {
        $dashboard = file_get_contents(resource_path('js/pages/Dashboard.vue'));
        $sidebar = file_get_contents(resource_path('js/components/AppSidebar.vue'));

        $this->assertIsString($dashboard);
        $this->assertIsString($sidebar);
        $this->assertStringContainsString('adsIndex.url()', $dashboard);
        $this->assertStringContainsString('View all', $dashboard);
        $this->assertStringContainsString("title: 'Ads'", $sidebar);
        $this->assertStringContainsString('adsIndex()', $sidebar);
    }

    public function test_dashboard_insights_preview_caps_active_ads_at_two(): void
    {
        $user = User::factory()->create();
        BrandProfile::factory()->for($user)->create();
        $account = TrackedAccount::factory()->for($user)->create();

        foreach (range(1, 5) as $n) {
            SocialAd::factory()->create([
                'social_account_id' => $account->social_account_id,
                'title' => "Ad {$n}",
                'last_seen_at' => now()->subMinutes($n),
            ]);
        }

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->loadDeferredProps('board', fn (Assert $page) => $page
                    ->has('insights.ads', 2)
                    ->where('insights.paid_vs_organic.running_ads', 5)
                )
            );
    }
}
