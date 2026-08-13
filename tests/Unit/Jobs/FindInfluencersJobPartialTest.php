<?php

namespace Tests\Unit\Jobs;

use App\Exceptions\InsufficientInfluencerSuggestionsException;
use App\Jobs\FindInfluencersJob;
use App\Models\BrandProfile;
use App\Models\User;
use App\Services\Billing\UsageBillingService;
use App\Services\Billing\VendorUsageCharger;
use App\Services\Influencers\InfluencerDiscoveryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\TestCase;

class FindInfluencersJobPartialTest extends TestCase
{
    use RefreshDatabase;

    public function test_completed_run_under_min_suggestions_sets_partial_flag(): void
    {
        config(['snitch.influencer_find.min_suggestions' => 6]);

        $user = User::factory()->create();
        BrandProfile::factory()->for($user)->create();
        app(UsageBillingService::class)->creditClaimBonus($user);

        $runId = (string) Str::uuid();
        Cache::put(FindInfluencersJob::cacheKeyFor($user->id, $runId), [
            'status' => 'queued',
            'filters' => [
                'platforms' => ['instagram'],
                'brief' => 'Find creators',
                'min_followers' => 1000,
                'max_followers' => 25000,
            ],
            'brief' => 'Find creators',
            'suggestions' => [],
            'decisions' => [],
            'error' => null,
        ], now()->addHour());

        $discovery = $this->createMock(InfluencerDiscoveryService::class);
        $discovery->expects($this->once())
            ->method('discover')
            ->willReturn([
                [
                    'platform' => 'instagram',
                    'handle' => 'midtier',
                    'url' => 'https://www.instagram.com/midtier/',
                    'display_name' => 'Mid Tier',
                    'followers' => 12000,
                    'fit_reason' => 'Fits brief',
                ],
                [
                    'platform' => 'instagram',
                    'handle' => 'another',
                    'url' => 'https://www.instagram.com/another/',
                    'display_name' => 'Another',
                    'followers' => 8000,
                    'fit_reason' => 'Also fits',
                ],
            ]);

        $this->app->instance(InfluencerDiscoveryService::class, $discovery);

        (new FindInfluencersJob($user->id, $runId, [
            'platforms' => ['instagram'],
            'language' => null,
            'min_followers' => 1000,
            'max_followers' => 25000,
            'brief' => 'Find creators',
        ]))->handle(
            $discovery,
            app(VendorUsageCharger::class),
            app(UsageBillingService::class),
        );

        $payload = Cache::get(FindInfluencersJob::cacheKeyFor($user->id, $runId));

        $this->assertSame('completed', $payload['status'] ?? null);
        $this->assertTrue($payload['partial'] ?? false);
        $this->assertCount(2, $payload['suggestions'] ?? []);
    }

    public function test_zero_verified_run_does_not_charge_vendors(): void
    {
        $user = User::factory()->create();
        BrandProfile::factory()->for($user)->create();
        app(UsageBillingService::class)->creditClaimBonus($user);

        $runId = (string) Str::uuid();
        Cache::put(FindInfluencersJob::cacheKeyFor($user->id, $runId), [
            'status' => 'queued',
            'filters' => [
                'platforms' => ['instagram'],
                'brief' => 'Find creators',
            ],
            'brief' => 'Find creators',
            'suggestions' => [],
            'decisions' => [],
            'error' => null,
        ], now()->addHour());

        $discovery = $this->createMock(InfluencerDiscoveryService::class);
        $discovery->expects($this->once())
            ->method('discover')
            ->willThrowException(new InsufficientInfluencerSuggestionsException(
                [],
                'Only 0 verified influencer profiles found (need at least 6).',
            ));

        $charger = $this->createMock(VendorUsageCharger::class);
        $charger->expects($this->never())->method('chargeFirecrawl');
        $charger->expects($this->never())->method('chargeNanoGpt');
        $charger->expects($this->never())->method('chargePulledApifyRuns');
        $charger->expects($this->never())->method('chargePulledTikHubRuns');

        $this->app->instance(InfluencerDiscoveryService::class, $discovery);

        (new FindInfluencersJob($user->id, $runId, [
            'platforms' => ['instagram'],
            'language' => null,
            'min_followers' => null,
            'max_followers' => null,
            'brief' => 'Find creators',
        ]))->handle(
            $discovery,
            $charger,
            app(UsageBillingService::class),
        );

        $payload = Cache::get(FindInfluencersJob::cacheKeyFor($user->id, $runId));
        $this->assertSame('failed', $payload['status'] ?? null);
    }
}
