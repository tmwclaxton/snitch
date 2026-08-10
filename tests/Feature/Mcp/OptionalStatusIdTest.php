<?php

namespace Tests\Feature\Mcp;

use App\Jobs\AutofillBrandFromWebsiteJob;
use App\Jobs\SuggestCompetitorsJob;
use App\Mcp\Servers\SnitchServer;
use App\Mcp\Tools\AutofillStatusTool;
use App\Mcp\Tools\SuggestCompetitorsStatusTool;
use App\Models\BrandProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OptionalStatusIdTest extends TestCase
{
    use RefreshDatabase;

    public function test_autofill_status_uses_latest_when_autofill_id_omitted(): void
    {
        $user = User::factory()->create();
        BrandProfile::factory()->for($user)->create();
        $autofillId = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';

        Cache::put(AutofillBrandFromWebsiteJob::latestCacheKeyFor($user->id), $autofillId, now()->addHour());
        Cache::put(AutofillBrandFromWebsiteJob::cacheKeyFor($user->id, $autofillId), [
            'status' => 'completed',
            'website' => 'https://mercury.com/',
            'fields' => [
                'name' => 'Mercury',
                'website' => 'https://mercury.com/',
                'description' => 'Banking for startups',
                'own_handles' => [],
            ],
            'error' => null,
        ], now()->addHour());

        Sanctum::actingAs($user);

        SnitchServer::tool(AutofillStatusTool::class, [
            'wait_seconds' => 0,
        ])
            ->assertOk()
            ->assertSee($autofillId)
            ->assertSee('completed')
            ->assertSee('Mercury');
    }

    public function test_autofill_status_errors_when_no_latest_and_id_omitted(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        SnitchServer::tool(AutofillStatusTool::class, [
            'wait_seconds' => 0,
        ])
            ->assertSee('No autofill_id provided');
    }

    public function test_suggest_competitors_status_uses_active_when_suggest_id_omitted(): void
    {
        $user = User::factory()->create();
        BrandProfile::factory()->for($user)->create();
        $suggestId = 'cccccccc-cccc-4ccc-8ccc-cccccccccccc';

        Cache::put(SuggestCompetitorsJob::activeCacheKeyFor($user->id), $suggestId, now()->addHours(2));
        Cache::put(SuggestCompetitorsJob::cacheKeyFor($user->id, $suggestId), [
            'status' => 'processing',
            'suggestions' => [
                [
                    'platform' => 'linkedin',
                    'handle' => 'brexhq',
                    'display_name' => 'Brex',
                ],
            ],
            'error' => null,
        ], now()->addHours(2));

        Sanctum::actingAs($user);

        SnitchServer::tool(SuggestCompetitorsStatusTool::class, [
            'wait_seconds' => 0,
        ])
            ->assertOk()
            ->assertSee($suggestId)
            ->assertSee('brexhq');
    }

    public function test_suggest_competitors_status_prefers_latest_over_active(): void
    {
        $user = User::factory()->create();
        $latestId = 'dddddddd-dddd-4ddd-8ddd-dddddddddddd';
        $activeId = 'eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee';

        Cache::put(SuggestCompetitorsJob::latestCacheKeyFor($user->id), $latestId, now()->addHours(2));
        Cache::put(SuggestCompetitorsJob::activeCacheKeyFor($user->id), $activeId, now()->addHours(2));
        Cache::put(SuggestCompetitorsJob::cacheKeyFor($user->id, $latestId), [
            'status' => 'completed',
            'suggestions' => [
                [
                    'platform' => 'instagram',
                    'handle' => 'ramp',
                ],
            ],
            'error' => null,
        ], now()->addHours(2));
        Cache::put(SuggestCompetitorsJob::cacheKeyFor($user->id, $activeId), [
            'status' => 'processing',
            'suggestions' => [
                [
                    'platform' => 'instagram',
                    'handle' => 'staleactive',
                ],
            ],
            'error' => null,
        ], now()->addHours(2));

        Sanctum::actingAs($user);

        SnitchServer::tool(SuggestCompetitorsStatusTool::class, [
            'wait_seconds' => 0,
        ])
            ->assertOk()
            ->assertSee($latestId)
            ->assertSee('ramp')
            ->assertDontSee('staleactive');
    }
}
