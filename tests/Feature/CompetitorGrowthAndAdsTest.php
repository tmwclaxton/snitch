<?php

namespace Tests\Feature;

use App\Enums\AnalysisStatus;
use App\Enums\Platform;
use App\Models\BrandProfile;
use App\Models\FollowerSnapshot;
use App\Models\Post;
use App\Models\PostAnalysis;
use App\Models\SocialAd;
use App\Models\TrackedAccount;
use App\Models\User;
use App\Services\Competitors\CompetitorAdsFinder;
use App\Services\Competitors\CompetitorInsightsBuilder;
use App\Services\Tracking\FollowerSnapshotRecorder;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CompetitorGrowthAndAdsTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_omits_not_in_this_build_copy(): void
    {
        $dashboard = file_get_contents(resource_path('js/pages/Dashboard.vue'));
        $show = file_get_contents(resource_path('js/pages/competitors/Show.vue'));

        $this->assertIsString($dashboard);
        $this->assertIsString($show);
        $this->assertStringNotContainsString('not in this build', $dashboard);
        $this->assertStringNotContainsString('Not click-through', $dashboard);
        $this->assertStringNotContainsString('Not conversion', $show);
        $this->assertStringContainsString('CTA clicks', $dashboard);
        $this->assertStringContainsString('Growth', $dashboard);
        $this->assertStringContainsString('Active ads', $dashboard);
    }

    public function test_insights_include_growth_ads_and_cta_clicks(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-21 12:00:00'));

        try {
            $user = User::factory()->create();
            BrandProfile::factory()->for($user)->create();
            $account = TrackedAccount::factory()->for($user)->create([
                'platform' => Platform::Instagram,
                'followers' => 12000,
            ]);

            FollowerSnapshot::factory()->create([
                'social_account_id' => $account->social_account_id,
                'followers' => 10000,
                'captured_on' => '2026-08-20',
            ]);
            FollowerSnapshot::factory()->create([
                'social_account_id' => $account->social_account_id,
                'followers' => 11000,
                'captured_on' => '2026-09-14',
            ]);
            FollowerSnapshot::factory()->create([
                'social_account_id' => $account->social_account_id,
                'followers' => 12000,
                'captured_on' => '2026-09-21',
            ]);

            $post = Post::factory()->forAccount($account)->create([
                'posted_at' => now()->subDay(),
                'metrics' => [
                    'views' => 1000,
                    'likes' => 10,
                    'comments' => 1,
                    'shares' => 1,
                    'clicks' => 40,
                ],
            ]);
            PostAnalysis::factory()->for($post)->create([
                'status' => AnalysisStatus::Completed,
                'cta' => 'Book a table',
            ]);

            SocialAd::factory()->create([
                'social_account_id' => $account->social_account_id,
                'title' => 'Autumn set menu',
                'url' => 'https://www.facebook.com/ads/library/?id=99',
            ]);

            $insights = app(CompetitorInsightsBuilder::class)->forUser($user);

            $this->assertSame(12000, $insights['growth']['followers']);
            $this->assertSame(1000, $insights['growth']['week_delta']);
            $this->assertSame(9.1, $insights['growth']['week_pct']);
            $this->assertSame(2000, $insights['growth']['month_delta']);
            $this->assertSame(40, $insights['cta_clicks']['clicks']);
            $this->assertSame(1, $insights['cta_clicks']['posts_with_cta']);
            $this->assertSame('Book a table', $insights['ctas'][0]['term']);
            $this->assertSame('Book a table', $insights['ctas'][0]['lines'][0]['text']);
            $this->assertSame('Autumn set menu', $insights['ads'][0]['title']);
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_snapshot_recorder_writes_one_row_per_day(): void
    {
        $account = TrackedAccount::factory()->create([
            'followers' => 500,
        ]);

        $recorder = app(FollowerSnapshotRecorder::class);
        $recorder->recordFromAccount($account);
        $account->followers = 520;
        $recorder->recordFromAccount($account);

        $this->assertSame(1, FollowerSnapshot::query()->count());
        $this->assertSame(520, FollowerSnapshot::query()->first()?->followers);
    }

    public function test_ads_finder_persists_library_hits(): void
    {
        config(['snitch.firecrawl.api_key' => 'test-key']);
        Http::fake([
            '*' => Http::response([
                'success' => true,
                'data' => [
                    [
                        'url' => 'https://www.facebook.com/ads/library/?id=123',
                        'title' => 'Live from the bar',
                        'description' => 'Book tonight',
                    ],
                    [
                        'url' => 'https://example.com/not-ads',
                        'title' => 'Ignore',
                        'description' => '',
                    ],
                ],
            ]),
        ]);

        $account = TrackedAccount::factory()->create([
            'platform' => Platform::Instagram,
            'handle' => 'letsgosocialuk',
            'display_name' => "Let's Go Social",
        ]);

        app(CompetitorAdsFinder::class)->refresh($account);

        $this->assertDatabaseHas('social_ads', [
            'social_account_id' => $account->social_account_id,
            'title' => 'Live from the bar',
            'url' => 'https://www.facebook.com/ads/library/?id=123',
        ]);
        $this->assertSame(1, SocialAd::query()->count());
    }
}
