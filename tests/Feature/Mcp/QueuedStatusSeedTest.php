<?php

namespace Tests\Feature\Mcp;

use App\Jobs\FindInfluencersJob;
use App\Jobs\SuggestCompetitorsJob;
use App\Mcp\Servers\SnitchServer;
use App\Mcp\Tools\FindInfluencersTool;
use App\Mcp\Tools\SuggestCompetitorsTool;
use App\Models\BrandProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class QueuedStatusSeedTest extends TestCase
{
    use RefreshDatabase;

    public function test_suggest_competitors_seeds_queued_status_before_job_runs(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        BrandProfile::factory()->create(['user_id' => $user->id]);
        Sanctum::actingAs($user);

        SnitchServer::tool(SuggestCompetitorsTool::class, [
            'wait_seconds' => 0,
        ])
            ->assertOk()
            ->assertSee('queued');

        $suggestId = Cache::get(SuggestCompetitorsJob::activeCacheKeyFor($user->id));
        $this->assertIsString($suggestId);
        $this->assertNull(Cache::get(SuggestCompetitorsJob::latestCacheKeyFor($user->id)));

        $cached = Cache::get(SuggestCompetitorsJob::cacheKeyFor($user->id, $suggestId));
        $this->assertIsArray($cached);
        $this->assertSame('pending', $cached['status'] ?? null);

        Queue::assertPushed(SuggestCompetitorsJob::class);
    }

    public function test_find_influencers_seeds_queued_status_before_job_runs(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        BrandProfile::factory()->create(['user_id' => $user->id]);
        Sanctum::actingAs($user);

        SnitchServer::tool(FindInfluencersTool::class, [
            'platform' => 'tiktok',
            'brief' => 'Streetwear creators for young women',
            'wait_seconds' => 0,
        ])
            ->assertOk()
            ->assertSee('queued');

        $runId = Cache::get(FindInfluencersJob::latestCacheKeyFor($user->id));
        $this->assertIsString($runId);

        $cached = Cache::get(FindInfluencersJob::cacheKeyFor($user->id, $runId));
        $this->assertIsArray($cached);
        $this->assertSame('queued', $cached['status'] ?? null);

        Queue::assertPushed(FindInfluencersJob::class);
    }
}
