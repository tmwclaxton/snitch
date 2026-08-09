<?php

namespace Tests\Unit\Services\Influencers;

use App\Models\BrandProfile;
use App\Models\User;
use App\Services\Analysis\NanoGptClient;
use App\Services\Apify\ApifyClient;
use App\Services\Apify\PlatformAdapterManager;
use App\Services\Firecrawl\FirecrawlClient;
use App\Services\Influencers\InfluencerDiscoveryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InfluencerDiscoveryServiceTest extends TestCase
{
    use RefreshDatabase;

    private function service(): InfluencerDiscoveryService
    {
        return new InfluencerDiscoveryService(
            $this->createMock(FirecrawlClient::class),
            $this->createMock(NanoGptClient::class),
            $this->createMock(PlatformAdapterManager::class),
            $this->createMock(ApifyClient::class),
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

    public function test_normalize_platforms_filters_unknown(): void
    {
        config(['snitch.influencer_find.platforms' => ['instagram', 'tiktok', 'youtube']]);

        $normalized = $this->service()->normalizePlatforms(['instagram', 'myspace', 'TIKTOK']);

        $this->assertSame(['instagram', 'tiktok'], $normalized);
    }
}
