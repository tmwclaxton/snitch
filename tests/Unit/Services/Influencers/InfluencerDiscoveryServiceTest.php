<?php

namespace Tests\Unit\Services\Influencers;

use App\Models\BrandProfile;
use App\Models\User;
use App\Services\Analysis\NanoGptClient;
use App\Services\Apify\Adapters\InstagramAdapter;
use App\Services\Apify\Adapters\TikTokAdapter;
use App\Services\Apify\Adapters\YoutubeAdapter;
use App\Services\Apify\ApifyClient;
use App\Services\Apify\PlatformAdapterManager;
use App\Services\Firecrawl\FirecrawlClient;
use App\Services\Influencers\InfluencerDiscoveryService;
use App\Services\Scraping\ApifyMonthlyCapGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InfluencerDiscoveryServiceTest extends TestCase
{
    use RefreshDatabase;

    private function service(
        ?PlatformAdapterManager $adapters = null,
        ?ApifyClient $apify = null,
        ?ApifyMonthlyCapGate $gate = null,
        ?NanoGptClient $nano = null,
    ): InfluencerDiscoveryService {
        return new InfluencerDiscoveryService(
            $this->createMock(FirecrawlClient::class),
            $nano ?? $this->createMock(NanoGptClient::class),
            $adapters ?? $this->createMock(PlatformAdapterManager::class),
            $apify ?? $this->createMock(ApifyClient::class),
            $gate ?? $this->createMock(ApifyMonthlyCapGate::class),
        );
    }

    public function test_search_queries_bias_to_creators_and_platforms(): void
    {
        $user = User::factory()->create();
        $brand = BrandProfile::factory()->for($user)->create([
            'name' => 'Bean Cart',
            'description' => 'Specialty coffee cafe in Brighton',
        ]);

        $queries = $this->service()->searchQueries($brand, [
            'platforms' => ['instagram', 'tiktok'],
            'language' => 'English',
            'min_followers' => 1000,
            'max_followers' => 50000,
            'brief' => 'Local cafe micro influencers for latte art and brunch',
        ], ['instagram', 'tiktok']);

        $joined = implode("\n", $queries);

        $this->assertStringContainsString('influencer', $joined);
        $this->assertStringContainsString('creator', $joined);
        $this->assertStringContainsString('site:instagram.com', $joined);
        $this->assertStringContainsString('site:tiktok.com/@', $joined);
        $this->assertStringContainsString('micro influencer', $joined);
        $this->assertStringContainsString('English', $joined);
    }

    public function test_followers_in_range_allows_unknown_and_filters_known(): void
    {
        $service = $this->service();

        $this->assertTrue($service->followersInRange(null, 1000, 50000));
        $this->assertTrue($service->followersInRange(12000, 1000, 50000));
        $this->assertFalse($service->followersInRange(200, 1000, 50000));
        $this->assertFalse($service->followersInRange(90000, 1000, 50000));
    }

    public function test_extract_followers_reads_common_actor_fields(): void
    {
        $service = $this->service();

        $this->assertSame(15000, $service->extractFollowers([
            ['ownerUsername' => 'x', 'followersCount' => 15000],
        ]));

        $this->assertSame(2200, $service->extractFollowers([
            ['authorMeta' => ['fans' => 2200]],
        ]));

        $this->assertSame(8800, $service->extractFollowers([
            ['about' => ['numberOfFollowers' => 8800]],
        ]));

        $this->assertNull($service->extractFollowers([
            ['caption' => 'no followers here'],
        ]));
    }

    public function test_search_queries_include_single_platform_site_bias(): void
    {
        $user = User::factory()->create();
        $brand = BrandProfile::factory()->for($user)->create([
            'name' => 'Stride Co',
            'description' => 'DTC sneaker streetwear brand',
        ]);

        $queries = $this->service()->searchQueries($brand, [
            'platforms' => ['instagram'],
            'language' => 'English',
            'min_followers' => 10000,
            'max_followers' => 250000,
            'brief' => 'Sneaker fashion Instagram creators',
        ], ['instagram']);

        $joined = implode("\n", $queries);

        $this->assertStringContainsString('site:instagram.com', $joined);
        $this->assertStringNotContainsString('site:tiktok.com', $joined);
        $this->assertStringContainsString('sneaker', strtolower($joined));
    }

    public function test_reject_org_or_brand_keys_uses_nanogpt_json(): void
    {
        $nano = $this->createMock(NanoGptClient::class);
        $nano->expects($this->once())
            ->method('chatJson')
            ->willReturn([
                'reject' => [
                    ['platform' => 'instagram', 'handle' => 'foundersfactory', 'reason' => 'incubator'],
                    ['platform' => 'instagram', 'handle' => 'seedcamp', 'reason' => 'accelerator'],
                    ['platform' => 'instagram', 'handle' => 'notalist', 'reason' => 'hallucination'],
                ],
            ]);

        config(['snitch.nanogpt.api_key' => 'test-key']);

        $service = $this->service(nano: $nano);

        $reject = $service->rejectOrgOrBrandKeys([
            ['platform' => 'instagram', 'handle' => 'fundingfiona', 'name' => 'Funding Fiona'],
            ['platform' => 'instagram', 'handle' => 'foundersfactory', 'name' => 'Founders Factory'],
            ['platform' => 'instagram', 'handle' => 'seedcamp', 'display_name' => 'Seedcamp'],
        ]);

        $this->assertTrue($reject['instagram:foundersfactory'] ?? false);
        $this->assertTrue($reject['instagram:seedcamp'] ?? false);
        $this->assertArrayNotHasKey('instagram:fundingfiona', $reject);
        $this->assertArrayNotHasKey('instagram:notalist', $reject);
    }

    public function test_filter_creator_candidates_drops_rejected_and_fails_soft_on_bad_json(): void
    {
        $nano = $this->createMock(NanoGptClient::class);
        $nano->expects($this->exactly(2))
            ->method('chatJson')
            ->willReturnOnConsecutiveCalls(
                ['reject' => [['platform' => 'instagram', 'handle' => 'brandhq', 'reason' => 'brand']]],
                null,
            );

        config(['snitch.nanogpt.api_key' => 'test-key']);

        $service = $this->service(nano: $nano);

        $filtered = $service->filterCreatorCandidates([
            ['name' => 'Creator', 'platform' => 'instagram', 'handle' => 'creatorone', 'seed' => 'model-seed'],
            ['name' => 'Brand HQ', 'platform' => 'instagram', 'handle' => 'brandhq', 'seed' => 'apify-search'],
        ]);

        $this->assertSame(['creatorone'], array_column($filtered, 'handle'));

        $keptOnBadJson = $service->filterCreatorCandidates([
            ['name' => 'Anyone', 'platform' => 'instagram', 'handle' => 'anyone', 'seed' => 'firecrawl'],
        ]);

        $this->assertSame(['anyone'], array_column($keptOnBadJson, 'handle'));
    }

    public function test_reject_org_or_brand_keys_skips_without_api_key(): void
    {
        config(['snitch.nanogpt.api_key' => '']);

        $nano = $this->createMock(NanoGptClient::class);
        $nano->expects($this->never())->method('chatJson');

        $service = $this->service(nano: $nano);

        $this->assertSame([], $service->rejectOrgOrBrandKeys([
            ['platform' => 'instagram', 'handle' => 'seedcamp', 'name' => 'Seedcamp'],
        ]));
    }

    public function test_merge_candidates_dedupes_prefers_followers_and_interleaves_seeds(): void
    {
        config(['snitch.influencer_find.max_resolves' => 10]);

        $merged = $this->service()->mergeCandidates(
            ['instagram'],
            [
                ['name' => 'A', 'platform' => 'instagram', 'handle' => 'alpha', 'source' => 'hit', 'seed' => 'firecrawl'],
                ['name' => 'B', 'platform' => 'instagram', 'handle' => 'bravo', 'source' => 'hit', 'seed' => 'firecrawl'],
            ],
            [
                ['name' => 'C', 'platform' => 'instagram', 'handle' => 'charlie', 'source' => 'model-seed', 'seed' => 'model-seed'],
                ['name' => 'A2', 'platform' => 'instagram', 'handle' => 'alpha', 'source' => 'model-seed', 'seed' => 'model-seed'],
            ],
            [
                [
                    'name' => 'D',
                    'platform' => 'instagram',
                    'handle' => 'delta',
                    'source' => 'apify-search',
                    'seed' => 'apify-search',
                    'followers' => 4200,
                ],
                [
                    'name' => 'A3',
                    'platform' => 'instagram',
                    'handle' => 'alpha',
                    'source' => 'apify-search',
                    'seed' => 'apify-search',
                    'followers' => 8800,
                ],
            ],
        );

        $handles = array_column($merged, 'handle');
        $this->assertContains('alpha', $handles);
        $this->assertContains('delta', $handles);
        $this->assertContains('bravo', $handles);
        $this->assertContains('charlie', $handles);
        $this->assertCount(1, array_filter($merged, fn (array $row): bool => $row['handle'] === 'alpha'));

        $alpha = collect($merged)->firstWhere('handle', 'alpha');
        $this->assertSame(8800, $alpha['followers'] ?? null);
        $this->assertSame('apify-search', $alpha['seed'] ?? null);

        // Interleave starts with apify-search so delta (or alpha) should appear early.
        $this->assertContains($merged[0]['seed'] ?? null, ['apify-search']);
    }

    public function test_apify_search_queries_are_platform_and_topic_aware(): void
    {
        $user = User::factory()->create();
        $brand = BrandProfile::factory()->for($user)->create([
            'name' => 'GrantGunner',
            'description' => 'AI grant discovery for startups',
        ]);

        $filters = [
            'platforms' => ['instagram'],
            'language' => 'English',
            'min_followers' => 1000,
            'max_followers' => 15000,
            'brief' => 'UK startup grants fundraising creators on Instagram',
        ];

        $ig = $this->service()->apifySearchQueries($brand, $filters, 'instagram');
        $tt = $this->service()->apifySearchQueries($brand, $filters, 'tiktok');
        $yt = $this->service()->apifySearchQueries($brand, $filters, 'youtube');

        $this->assertNotEmpty($ig);
        $this->assertStringContainsString('grant', strtolower($ig[0]));
        $this->assertStringContainsString('influencer', strtolower($ig[0]));
        $this->assertStringContainsString('English', $ig[0]);
        $this->assertStringContainsString('creator', strtolower($tt[0]));
        $this->assertStringContainsString('Shorts', $yt[0]);
    }

    public function test_candidates_from_apify_search_items_extract_handles_and_followers(): void
    {
        $service = $this->service();

        $ig = $service->candidatesFromApifySearchItems('instagram', [
            ['username' => 'grantvoice', 'fullName' => 'Grant Voice', 'followersCount' => 5200],
            ['username' => 'grantvoice', 'fullName' => 'Dup', 'followersCount' => 1],
            ['username' => 'bigbrandhq', 'fullName' => 'Big Brand HQ', 'followersCount' => 3000],
        ], 10);

        $this->assertCount(1, array_filter($ig, fn (array $row): bool => $row['handle'] === 'grantvoice'));
        $grant = collect($ig)->firstWhere('handle', 'grantvoice');
        $this->assertSame(5200, $grant['followers'] ?? null);
        $this->assertSame('apify-search', $grant['seed'] ?? null);

        $tt = $service->candidatesFromApifySearchItems('tiktok', [
            ['authorMeta' => ['name' => 'fashionfit', 'nickName' => 'Fashion Fit', 'fans' => 9100]],
        ], 5);

        $this->assertSame('fashionfit', $tt[0]['handle']);
        $this->assertSame(9100, $tt[0]['followers']);

        $yt = $service->candidatesFromApifySearchItems('youtube', [
            [
                'channelUsername' => 'startupshorts',
                'channelName' => 'Startup Shorts',
                'aboutChannelInfo' => ['numberOfSubscribers' => 12000],
            ],
        ], 5);

        $this->assertSame('startupshorts', $yt[0]['handle']);
        $this->assertSame(12000, $yt[0]['followers']);
    }

    public function test_prioritize_candidates_for_verify_orders_known_in_band_first(): void
    {
        $ordered = $this->service()->prioritizeCandidatesForVerify([
            ['name' => 'u', 'platform' => 'instagram', 'handle' => 'unknown', 'seed' => 'firecrawl'],
            ['name' => 'o', 'platform' => 'instagram', 'handle' => 'outofband', 'seed' => 'apify-search', 'followers' => 90000],
            ['name' => 'i', 'platform' => 'instagram', 'handle' => 'inband', 'seed' => 'apify-search', 'followers' => 4000],
        ], 1000, 15000);

        $this->assertSame(['inband', 'unknown', 'outofband'], array_column($ordered, 'handle'));
    }

    public function test_seed_from_model_marks_model_seed_source(): void
    {
        $user = User::factory()->create();
        $brand = BrandProfile::factory()->for($user)->create([
            'name' => 'GrantGunner',
            'description' => 'Grant discovery',
        ]);

        $nano = $this->createMock(NanoGptClient::class);
        $nano->expects($this->once())
            ->method('chat')
            ->willReturn(['choices' => [['message' => ['content' => '']]]]);
        $nano->method('extractAssistantText')->willReturn(json_encode([
            'influencers' => [
                [
                    'name' => 'Funding Fiona',
                    'platform' => 'instagram',
                    'handle' => 'fundingfiona',
                    'source' => 'should-be-overwritten',
                ],
            ],
        ]));

        config([
            'snitch.nanogpt.api_key' => 'test-key',
            'snitch.influencer_find.model_seed_count' => 12,
            'snitch.influencer_find.platforms' => ['instagram', 'tiktok', 'youtube'],
        ]);

        $service = $this->service(nano: $nano);

        $rows = $service->seedFromModel($brand, [
            'platforms' => ['instagram'],
            'language' => 'English',
            'min_followers' => 1000,
            'max_followers' => 15000,
            'brief' => 'UK grants startup creators',
        ], ['instagram']);

        $this->assertCount(1, $rows);
        $this->assertSame('fundingfiona', $rows[0]['handle']);
        $this->assertSame('model-seed', $rows[0]['source']);
        $this->assertSame('model-seed', $rows[0]['seed']);
    }

    public function test_instagram_adapter_search_and_resolve_use_details(): void
    {
        $adapter = app(InstagramAdapter::class);

        $search = $adapter->searchUsersActorInput('startup grants creator', 12);
        $this->assertSame('user', $search['searchType']);
        $this->assertSame('details', $search['resultsType']);
        $this->assertSame(12, $search['searchLimit']);

        $resolve = $adapter->resolveActorJob('grantvoice');
        $this->assertSame('details', $resolve['input']['resultsType']);
        $this->assertSame(['https://instagram.com/grantvoice'], $resolve['input']['directUrls']);
    }

    public function test_tiktok_and_youtube_search_inputs(): void
    {
        $tiktok = app(TikTokAdapter::class);
        $youtube = app(YoutubeAdapter::class);

        $tt = $tiktok->searchUsersActorInput('fashion creator', 15);
        $this->assertSame(['fashion creator'], $tt['searchQueries']);
        $this->assertSame('/user', $tt['searchSection']);
        $this->assertSame(15, $tt['maxProfilesPerQuery']);

        $yt = $youtube->searchChannelsActorInput('sneaker Shorts creator', 10);
        $this->assertSame(['sneaker Shorts creator'], $yt['searchQueries']);
        $this->assertSame(10, $yt['maxResultsShorts']);
    }
}
