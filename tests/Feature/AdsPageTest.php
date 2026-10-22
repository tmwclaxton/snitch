<?php

namespace Tests\Feature;

use App\Enums\Platform;
use App\Models\BrandProfile;
use App\Models\SocialAd;
use App\Models\TrackedAccount;
use App\Models\User;
use App\Services\Dashboard\DashboardMetrics;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdsPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_from_retired_ad_library_route(): void
    {
        $this->get(route('ads.index'))
            ->assertRedirect(route('login'));
    }

    public function test_authenticated_users_are_redirected_from_ad_library_to_dashboard(): void
    {
        $user = User::factory()->create();
        BrandProfile::factory()->for($user)->create();

        $this->actingAs($user)
            ->get(route('ads.index'))
            ->assertRedirect(route('dashboard'));

        $this->get('/ads')->assertRedirect('/dashboard');
        $this->assertFileExists(resource_path('js/pages/ad-library/Index.vue'));
        $this->assertFileDoesNotExist(resource_path('js/pages/ads/Index.vue'));
    }

    public function test_sidebar_keeps_competitors_without_ad_library_nav(): void
    {
        $sidebar = file_get_contents(resource_path('js/components/AppSidebar.vue'));

        $this->assertIsString($sidebar);
        $this->assertStringContainsString("title: 'Competitors'", $sidebar);
        $this->assertStringNotContainsString("title: 'Are they running ads?'", $sidebar);
        $this->assertStringNotContainsString("title: 'Ad Library'", $sidebar);
        $this->assertStringNotContainsString('AdsController', $sidebar);
    }

    public function test_dashboard_metrics_ads_panel_groups_active_ads_per_account(): void
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

        $payload = app(DashboardMetrics::class)->forUser($user, [], 30);

        $this->assertSame(5, $payload['ads_panel']['running_ads'] ?? null);
        $this->assertCount(1, $payload['ads_panel']['accounts'] ?? []);
        $this->assertSame('adclub', $payload['ads_panel']['accounts'][0]['handle'] ?? null);
        $this->assertCount(3, $payload['ads_panel']['accounts'][0]['ads'] ?? []);
        $this->assertStringContainsString('5 ads', (string) ($payload['ads_panel']['recommendation'] ?? ''));
    }
}
