<?php

namespace Tests\Feature\Mcp;

use App\Jobs\FindInfluencersJob;
use App\Jobs\SyncTrackedAccountJob;
use App\Mcp\Servers\SnitchServer;
use App\Mcp\Tools\InfluencerSearchStatusTool;
use App\Mcp\Tools\KeepInfluencerTool;
use App\Mcp\Tools\ListInfluencersTool;
use App\Mcp\Tools\RemoveInfluencerTool;
use App\Models\BrandProfile;
use App\Models\TrackedAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ListInfluencersToolTest extends TestCase
{
    use RefreshDatabase;

    public function test_list_influencers_includes_fit_reason_and_url(): void
    {
        $user = User::factory()->create();
        BrandProfile::factory()->for($user)->create();
        TrackedAccount::factory()->for($user)->influencer()->create([
            'platform' => 'instagram',
            'handle' => 'dealmaker',
            'url' => 'https://www.instagram.com/dealmaker/',
            'followers' => 12500,
            'fit_reason' => 'Strong mid-tier fashion audience for DTC collabs.',
        ]);
        $this->actingAs($user);

        SnitchServer::tool(ListInfluencersTool::class)
            ->assertOk()
            ->assertSee('dealmaker')
            ->assertSee('12500')
            ->assertSee('Strong mid-tier fashion audience for DTC collabs.')
            ->assertSee('https://www.instagram.com/dealmaker/');
    }

    public function test_remove_influencer_accepts_influencer_id_alias(): void
    {
        $user = User::factory()->create();
        $account = TrackedAccount::factory()->for($user)->influencer()->create([
            'platform' => 'instagram',
            'handle' => 'to-remove',
        ]);
        $this->actingAs($user);

        SnitchServer::tool(RemoveInfluencerTool::class, [
            'influencer_id' => $account->id,
        ])->assertOk()->assertSee('"deleted":true');

        $this->assertDatabaseMissing('tracked_accounts', ['id' => $account->id]);
    }

    public function test_influencer_search_status_exposes_fit_reason_on_suggestions(): void
    {
        $user = User::factory()->create();
        BrandProfile::factory()->for($user)->create();
        $runId = 'eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee';

        Cache::put(FindInfluencersJob::cacheKeyFor($user->id, $runId), [
            'status' => 'completed',
            'filters' => [
                'platforms' => ['instagram'],
                'brief' => 'Find creators',
            ],
            'brief' => 'Find creators',
            'suggestions' => [
                [
                    'platform' => 'instagram',
                    'handle' => 'fitcreator',
                    'url' => 'https://www.instagram.com/fitcreator/',
                    'display_name' => 'Fit Creator',
                    'avatar' => null,
                    'followers' => 12000,
                    'fit_reason' => 'Audience matches sneaker brief and posts UGC-style reviews.',
                ],
            ],
            'decisions' => [],
            'error' => null,
        ], now()->addHour());

        $this->actingAs($user);

        SnitchServer::tool(InfluencerSearchStatusTool::class, [
            'run_id' => $runId,
            'wait_seconds' => 0,
        ])
            ->assertOk()
            ->assertSee('fitcreator')
            ->assertSee('Audience matches sneaker brief and posts UGC-style reviews.')
            ->assertSee('https://www.instagram.com/fitcreator/');
    }

    public function test_influencer_search_status_uses_latest_when_run_id_omitted(): void
    {
        $user = User::factory()->create();
        BrandProfile::factory()->for($user)->create();
        $runId = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';

        Cache::put(FindInfluencersJob::latestCacheKeyFor($user->id), $runId, now()->addHour());
        Cache::put(FindInfluencersJob::cacheKeyFor($user->id, $runId), [
            'status' => 'completed',
            'filters' => ['platforms' => ['instagram'], 'brief' => 'Find creators'],
            'brief' => 'Find creators',
            'suggestions' => [
                [
                    'platform' => 'instagram',
                    'handle' => 'latestcreator',
                    'url' => 'https://www.instagram.com/latestcreator/',
                    'display_name' => 'Latest Creator',
                    'fit_reason' => 'Matches latest pointer brief.',
                ],
            ],
            'decisions' => [],
            'error' => null,
        ], now()->addHour());

        $this->actingAs($user);

        SnitchServer::tool(InfluencerSearchStatusTool::class, [
            'wait_seconds' => 0,
        ])
            ->assertOk()
            ->assertSee('latestcreator')
            ->assertSee($runId);
    }

    public function test_keep_influencer_persists_fit_reason_from_run(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        BrandProfile::factory()->for($user)->create();
        $runId = 'ffffffff-ffff-4fff-8fff-ffffffffffff';

        Cache::put(FindInfluencersJob::cacheKeyFor($user->id, $runId), [
            'status' => 'completed',
            'filters' => [
                'platforms' => ['tiktok'],
                'brief' => 'Find creators',
            ],
            'brief' => 'Find creators',
            'suggestions' => [
                [
                    'platform' => 'tiktok',
                    'handle' => 'keepfit',
                    'url' => 'https://www.tiktok.com/@keepfit',
                    'display_name' => 'Keep Fit',
                    'avatar' => null,
                    'followers' => 9000,
                    'fit_reason' => 'Short-form fitness demos that suit product seeding.',
                ],
            ],
            'decisions' => [],
            'error' => null,
        ], now()->addHour());

        $this->actingAs($user);

        SnitchServer::tool(KeepInfluencerTool::class, [
            'platform' => 'tiktok',
            'handle' => 'keepfit',
            'run_id' => $runId,
        ])
            ->assertOk()
            ->assertSee('Short-form fitness demos that suit product seeding.')
            ->assertSee('https://www.tiktok.com/@keepfit');

        $this->assertDatabaseHas('tracked_accounts', [
            'user_id' => $user->id,
            'platform' => 'tiktok',
            'handle' => 'keepfit',
            'followers' => 9000,
            'fit_reason' => 'Short-form fitness demos that suit product seeding.',
        ]);

        Queue::assertPushed(SyncTrackedAccountJob::class);

        $payload = Cache::get(FindInfluencersJob::cacheKeyFor($user->id, $runId));
        $this->assertSame('kept', $payload['decisions']['tiktok:keepfit'] ?? null);
    }
}
