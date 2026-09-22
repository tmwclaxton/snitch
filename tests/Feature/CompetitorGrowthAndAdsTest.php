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
        $this->assertStringContainsString('Posts with an ask', $dashboard);
        $this->assertStringContainsString('In the caption', $dashboard);
        $this->assertStringContainsString('Posts with an ask', $show);
        $this->assertStringNotContainsString('CTA clicks', $dashboard);
        $this->assertStringNotContainsString('CTA clicks', $show);
        $this->assertStringContainsString('Growth', $dashboard);
        $this->assertStringContainsString('Active ads', $dashboard);
        $this->assertStringContainsString('FollowerHistoryChart', $dashboard);
        $this->assertStringContainsString('Paid vs organic', $dashboard);
        $this->assertStringContainsString('Paid vs organic', $show);
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
            $this->assertSame(1, $insights['cta_clicks']['posts_with_cta']);
            $this->assertArrayNotHasKey('clicks', $insights['cta_clicks']);
            $this->assertSame('Book a table', $insights['ctas'][0]['term']);
            $this->assertSame('Book a table', $insights['ctas'][0]['lines'][0]['text']);
            $this->assertSame($post->id, $insights['ctas'][0]['lines'][0]['post_id']);
            $this->assertSame('Autumn set menu', $insights['ads'][0]['title']);
            $this->assertArrayHasKey('follower_series', $insights);
            $this->assertGreaterThanOrEqual(1, count($insights['follower_series']));
            $this->assertSame(0, $insights['paid_vs_organic']['sponsored']);
            $this->assertSame(1, $insights['paid_vs_organic']['organic']);
            $this->assertSame(1, $insights['paid_vs_organic']['running_ads']);
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_cta_line_links_to_the_newest_post_with_that_ask(): void
    {
        $user = User::factory()->create();
        BrandProfile::factory()->for($user)->create();
        $account = TrackedAccount::factory()->for($user)->create();

        $older = Post::factory()->forAccount($account)->create([
            'posted_at' => now()->subDays(3),
        ]);
        PostAnalysis::factory()->for($older)->create([
            'status' => AnalysisStatus::Completed,
            'cta' => 'Save this sequence',
        ]);

        $newer = Post::factory()->forAccount($account)->create([
            'posted_at' => now()->subDay(),
        ]);
        PostAnalysis::factory()->for($newer)->create([
            'status' => AnalysisStatus::Completed,
            'cta' => 'Save this sequence',
        ]);

        $insights = app(CompetitorInsightsBuilder::class)->forUser($user);
        $line = $insights['ctas'][0]['lines'][0];

        $this->assertSame($newer->id, $line['post_id']);
        $this->assertSame(2, $line['count']);

        $component = file_get_contents(resource_path('js/components/CtaLanguage.vue'));
        $this->assertIsString($component);
        $this->assertStringContainsString('feedShow.url(line.post_id)', $component);
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

        $this->assertSame(3, FollowerSnapshot::query()->count());
        $this->assertSame(
            520,
            FollowerSnapshot::query()->whereDate('captured_on', now()->toDateString())->value('followers'),
        );
    }

    public function test_first_snapshot_plants_week_and_month_baselines(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-22 12:00:00'));

        try {
            $user = User::factory()->create();
            BrandProfile::factory()->for($user)->create();
            $account = TrackedAccount::factory()->for($user)->create([
                'followers' => 1000,
            ]);
            app(FollowerSnapshotRecorder::class)->recordFromAccount($account);

            $this->assertSame(3, FollowerSnapshot::query()->count());
            $this->assertTrue(
                FollowerSnapshot::query()
                    ->where('social_account_id', $account->social_account_id)
                    ->whereDate('captured_on', '2026-09-15')
                    ->where('followers', 1000)
                    ->exists(),
            );
            $this->assertTrue(
                FollowerSnapshot::query()
                    ->where('social_account_id', $account->social_account_id)
                    ->whereDate('captured_on', '2026-08-23')
                    ->where('followers', 1000)
                    ->exists(),
            );

            $insights = app(CompetitorInsightsBuilder::class)->forUser($user);
            $this->assertSame(0, $insights['growth']['week_delta']);
            $this->assertSame(0, $insights['growth']['month_delta']);
            $this->assertGreaterThanOrEqual(2, count($insights['follower_series']));
        } finally {
            CarbonImmutable::setTestNow();
        }
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
                        'title' => "Let's Go Social - Ad Library",
                        'description' => "Live from the bar with Let's Go Social",
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
            'title' => "Let's Go Social - Ad Library",
            'url' => 'https://www.facebook.com/ads/library/?id=123',
        ]);
        $this->assertSame(1, SocialAd::query()->count());
    }
}
