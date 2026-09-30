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

    public function test_guests_are_redirected_from_ads_when_library_enabled(): void
    {
        config(['features.ad_library' => true]);

        $this->get(route('ads.index'))
            ->assertRedirect(route('login'));
    }

    public function test_authenticated_users_can_visit_ads_with_deferred_catalogue_when_enabled(): void
    {
        config(['features.ad_library' => true]);

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

    public function test_ad_library_redirects_to_dashboard_when_feature_disabled(): void
    {
        config(['features.ad_library' => false]);

        $user = User::factory()->create();
        BrandProfile::factory()->for($user)->create();

        $this->actingAs($user)
            ->get(route('ads.index'))
            ->assertRedirect(route('dashboard'));

        $this->get('/ads')->assertRedirect('/dashboard');
    }

    public function test_ads_page_avoids_adblock_sensitive_path_when_enabled(): void
    {
        config(['features.ad_library' => true]);

        $this->assertSame('/ad-library', parse_url(route('ads.index'), PHP_URL_PATH));

        $this->get('/ads')->assertRedirect('/ad-library');

        $this->assertFileExists(resource_path('js/pages/ad-library/Index.vue'));
        $this->assertFileDoesNotExist(resource_path('js/pages/ads/Index.vue'));
    }

    public function test_sidebar_uses_ads_dashboard_section_not_ad_library_nav(): void
    {
        $sidebar = file_get_contents(resource_path('js/components/AppSidebar.vue'));

        $this->assertIsString($sidebar);
        $this->assertStringContainsString("title: 'Are they running ads?'", $sidebar);
        $this->assertStringContainsString("title: 'Competitors'", $sidebar);
        $this->assertStringNotContainsString("title: 'Ad Library'", $sidebar);
        $this->assertStringNotContainsString('AdsController', $sidebar);
    }

    public function test_dashboard_ads_panel_groups_active_ads_per_account(): void
    {
        $user = User::factory()->create();
        BrandProfile::factory()->for($user)->create();
        $account = TrackedAccount::factory()->for($user)->forPlatform(Platform::Instagram)->create([
            'handle' => 'adclub',
        ]);

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
                ->component('Dashboard')
                ->missing('insights.ads')
                ->missing('insights.paid_vs_organic')
                ->loadDeferredProps('panel', fn (Assert $panel) => $panel
                    ->where('ads_panel.running_ads', 5)
                    ->has('ads_panel.accounts', 1)
                    ->where('ads_panel.accounts.0.handle', 'adclub')
                    ->has('ads_panel.accounts.0.ads', 3)
                    ->where('ads_panel.recommendation', fn (string $text): bool => str_contains($text, '5 ads'))
                )
            );
    }
}
