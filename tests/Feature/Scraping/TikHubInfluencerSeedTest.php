<?php

namespace Tests\Feature\Scraping;

use App\Models\BrandProfile;
use App\Models\User;
use App\Services\Apify\ApifyClient;
use App\Services\Apify\PlatformAdapterManager;
use App\Services\Influencers\InfluencerDiscoveryService;
use App\Services\Scraping\ApifyMonthlyCapGate;
use App\Services\TikHub\Adapters\InstagramAdapter as TikHubInstagramAdapter;
use App\Services\TikHub\TikHubClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

class TikHubInfluencerSeedTest extends TestCase
{
    use RefreshDatabase;

    public function test_seed_from_apify_search_uses_tikhub_when_capped(): void
    {
        config([
            'snitch.apify.token' => 'apify-token',
            'snitch.tikhub.api_key' => 'tikhub-key',
            'snitch.tikhub.base_url' => 'https://api.tikhub.test',
            'snitch.influencer_find.apify_search_limit' => 5,
        ]);

        $gate = Mockery::mock(ApifyMonthlyCapGate::class);
        $gate->shouldReceive('isApifyExhausted')->andReturn(true);
        $gate->shouldReceive('tikHubConfigured')->andReturn(true);
        $gate->shouldReceive('tikHubSupports')->andReturn(true);

        $tikhub = Mockery::mock(TikHubInstagramAdapter::class);
        $tikhub->shouldReceive('searchUsers')
            ->once()
            ->andReturn([
                [
                    'name' => 'Grant Voice',
                    'platform' => 'instagram',
                    'handle' => 'grantvoice',
                    'followers' => 4200,
                    'seed' => 'tikhub-search',
                ],
            ]);

        $manager = Mockery::mock(PlatformAdapterManager::class);
        $manager->shouldReceive('tikHubAdapter')->with('instagram')->andReturn($tikhub);
        $manager->shouldReceive('apifyAdapter')->never();

        $apify = Mockery::mock(ApifyClient::class);
        $apify->shouldReceive('runActor')->never();

        $this->app->instance(ApifyMonthlyCapGate::class, $gate);
        $this->app->instance(PlatformAdapterManager::class, $manager);
        $this->app->instance(ApifyClient::class, $apify);

        $user = User::factory()->create();
        $brand = BrandProfile::factory()->for($user)->create([
            'name' => 'GrantGunner',
            'description' => 'AI grant discovery',
        ]);

        $service = app(InfluencerDiscoveryService::class);
        $rows = $service->seedFromApifySearch($brand, [
            'platforms' => ['instagram'],
            'language' => 'English',
            'min_followers' => 1000,
            'max_followers' => 15000,
            'brief' => 'UK startup grants creators',
        ], ['instagram']);

        $this->assertCount(1, $rows);
        $this->assertSame('grantvoice', $rows[0]['handle']);
        $this->assertSame('tikhub-search', $rows[0]['seed']);
        $this->assertSame('tikhub-search', $rows[0]['source']);
    }

    public function test_tikhub_client_records_run_costs(): void
    {
        config([
            'snitch.tikhub.api_key' => 'tikhub-key',
            'snitch.tikhub.base_url' => 'https://api.tikhub.test',
            'billing.vendors.tikhub.endpoints.instagram.floor_usd' => 0.002,
        ]);

        Http::preventStrayRequests();
        Http::fake([
            'https://api.tikhub.test/*' => Http::response([
                'code' => 200,
                'data' => ['user' => ['username' => 'nike']],
            ]),
        ]);

        $client = app(TikHubClient::class);
        $client->get('/api/v1/instagram/v2/fetch_user_info', ['username' => 'nike'], 'instagram');

        $costs = $client->pullRunCosts();
        $this->assertCount(1, $costs);
        $this->assertSame(0.002, $costs[0]['cogsUsd']);
        $this->assertSame('instagram', $costs[0]['platform']);
    }
}
