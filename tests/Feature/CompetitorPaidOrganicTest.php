<?php

namespace Tests\Feature;

use App\Enums\AnalysisStatus;
use App\Enums\Platform;
use App\Models\BrandProfile;
use App\Models\Post;
use App\Models\PostAnalysis;
use App\Models\SocialAd;
use App\Models\TrackedAccount;
use App\Models\User;
use App\Services\Competitors\CompetitorAdsFinder;
use App\Services\Competitors\CompetitorInsightsBuilder;
use App\Support\SponsoredPostDetector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CompetitorPaidOrganicTest extends TestCase
{
    use RefreshDatabase;

    public function test_detector_flags_paid_partnership_payload_and_caption_tags(): void
    {
        $detector = app(SponsoredPostDetector::class);
        $account = TrackedAccount::factory()->create();

        $sponsored = Post::factory()->forAccount($account)->create([
            'caption' => 'Launch week',
            'raw_payload' => ['paidPartnership' => ['type' => 'PaidPartnership']],
        ]);
        $tagged = Post::factory()->forAccount($account)->create([
            'caption' => 'Thanks brand #ad for the kit',
            'raw_payload' => ['paidPartnership' => null],
        ]);
        $organic = Post::factory()->forAccount($account)->create([
            'caption' => 'Morning routine',
            'raw_payload' => ['paidPartnership' => null],
        ]);
        $topic = Post::factory()->forAccount($account)->create([
            'caption' => 'Offer drop',
            'raw_payload' => [],
        ]);
        PostAnalysis::factory()->for($topic)->create([
            'status' => AnalysisStatus::Completed,
            'topics' => ['Paid ads'],
        ]);
        $topic->load('analysis');

        $this->assertTrue($detector->looksSponsored($sponsored));
        $this->assertTrue($detector->looksSponsored($tagged));
        $this->assertFalse($detector->looksSponsored($organic));
        $this->assertTrue($detector->looksSponsored($topic));
    }

    public function test_insights_count_sponsored_posts_and_running_ads(): void
    {
        $user = User::factory()->create();
        BrandProfile::factory()->for($user)->create();
        $account = TrackedAccount::factory()->for($user)->create([
            'platform' => Platform::Instagram,
        ]);

        Post::factory()->forAccount($account)->create([
            'posted_at' => now()->subDay(),
            'caption' => 'Organic tip',
            'raw_payload' => ['paidPartnership' => null],
        ]);
        Post::factory()->forAccount($account)->create([
            'posted_at' => now()->subHours(3),
            'caption' => 'Collab #sponsored',
            'raw_payload' => [],
        ]);
        SocialAd::factory()->create([
            'social_account_id' => $account->social_account_id,
            'is_active' => true,
        ]);

        $insights = app(CompetitorInsightsBuilder::class)->forUser($user);

        $this->assertSame(1, $insights['paid_vs_organic']['sponsored']);
        $this->assertSame(1, $insights['paid_vs_organic']['organic']);
        $this->assertSame(1, $insights['paid_vs_organic']['running_ads']);
    }

    public function test_ads_finder_uses_page_library_id_from_posts(): void
    {
        config(['snitch.firecrawl.api_key' => '']);

        $account = TrackedAccount::factory()->create([
            'platform' => Platform::Facebook,
            'handle' => 'hellofresh',
            'display_name' => 'HelloFresh',
        ]);
        Post::factory()->forAccount($account)->create([
            'platform' => Platform::Facebook,
            'raw_payload' => [
                'pageAdLibrary' => ['id' => '320774061283785'],
            ],
        ]);

        app(CompetitorAdsFinder::class)->refresh($account);

        $this->assertDatabaseHas('social_ads', [
            'social_account_id' => $account->social_account_id,
            'title' => 'HelloFresh Ad Library',
        ]);
        $this->assertStringContainsString(
            'view_all_page_id=320774061283785',
            (string) SocialAd::query()->value('url'),
        );
    }

    public function test_ads_finder_rejects_unrelated_library_hits(): void
    {
        config(['snitch.firecrawl.api_key' => 'test-key']);
        Http::fake([
            '*' => Http::response([
                'success' => true,
                'data' => [
                    [
                        'url' => 'https://www.facebook.com/ads/library/?id=1',
                        'title' => 'Ad Library - Facebook',
                        'description' => 'Fuel your festival with sober-friendly chocolate.',
                    ],
                    [
                        'url' => 'https://www.facebook.com/ads/library/?id=2',
                        'title' => 'Gymshark - Ad Library',
                        'description' => 'Shop Gymshark seamless now.',
                    ],
                ],
            ]),
        ]);

        $account = TrackedAccount::factory()->create([
            'platform' => Platform::Instagram,
            'handle' => 'sobersocial_',
            'display_name' => 'Sober Social',
        ]);

        app(CompetitorAdsFinder::class)->refresh($account);

        $this->assertSame(0, SocialAd::query()->count());
    }
}
