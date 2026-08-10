<?php

namespace Tests\Feature\Mcp;

use App\Enums\TrackedAccountKind;
use App\Jobs\SuggestCompetitorsJob;
use App\Jobs\SyncTrackedAccountJob;
use App\Mcp\Servers\SnitchServer;
use App\Mcp\Tools\ConfirmCompetitorSuggestionsTool;
use App\Mcp\Tools\DismissCompetitorSuggestionsTool;
use App\Mcp\Tools\SuggestCompetitorsStatusTool;
use App\Mcp\Tools\SuggestCompetitorsTool;
use App\Models\BrandProfile;
use App\Models\TrackedAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CompetitorSuggestConfirmLoopTest extends TestCase
{
    use RefreshDatabase;

    public function test_server_instructions_require_confirm_after_suggest(): void
    {
        $attributes = (new \ReflectionClass(SnitchServer::class))->getAttributes(Instructions::class);
        $this->assertNotEmpty($attributes);

        $instructions = $attributes[0]->newInstance()->value;

        $this->assertStringContainsString('confirm_competitor_suggestions', $instructions);
        $this->assertStringContainsString('NOT tracked', $instructions);
    }

    public function test_suggest_tool_description_and_response_require_confirm(): void
    {
        Queue::fake();

        $attributes = (new \ReflectionClass(SuggestCompetitorsTool::class))->getAttributes(Description::class);
        $this->assertNotEmpty($attributes);
        $description = $attributes[0]->newInstance()->value;
        $this->assertStringContainsString('confirm_competitor_suggestions', $description);

        $user = User::factory()->create();
        BrandProfile::factory()->create(['user_id' => $user->id]);
        Sanctum::actingAs($user);

        SnitchServer::tool(SuggestCompetitorsTool::class)
            ->assertOk()
            ->assertSee('confirm_competitor_suggestions')
            ->assertSee('NOT tracked');
    }

    public function test_status_tool_reminds_agent_to_confirm_when_suggestions_exist(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $suggestId = (string) Str::uuid();
        Cache::put(SuggestCompetitorsJob::cacheKeyFor($user->id, $suggestId), [
            'status' => 'completed',
            'suggestions' => [
                [
                    'platform' => 'instagram',
                    'handle' => 'rivalbrand',
                    'display_name' => 'Rival Brand',
                ],
            ],
            'error' => null,
        ], now()->addHour());

        SnitchServer::tool(SuggestCompetitorsStatusTool::class, [
            'suggest_id' => $suggestId,
        ])
            ->assertOk()
            ->assertSee('confirm_competitor_suggestions')
            ->assertSee('NOT tracked');
    }

    public function test_confirm_creates_tracked_accounts_and_prunes_latest_suggestions(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $suggestId = (string) Str::uuid();
        $payload = [
            'status' => 'completed',
            'suggestions' => [
                [
                    'platform' => 'instagram',
                    'handle' => 'keepme',
                    'display_name' => 'Keep Me',
                    'url' => 'https://instagram.com/keepme',
                ],
                [
                    'platform' => 'tiktok',
                    'handle' => 'skipme',
                    'display_name' => 'Skip Me',
                ],
            ],
            'error' => null,
        ];

        Cache::put(SuggestCompetitorsJob::cacheKeyFor($user->id, $suggestId), $payload, now()->addHours(2));
        Cache::put(SuggestCompetitorsJob::latestCacheKeyFor($user->id), $suggestId, now()->addHours(2));

        SnitchServer::tool(ConfirmCompetitorSuggestionsTool::class, [
            'suggest_id' => $suggestId,
            'handles' => ['keepme'],
            'sync' => true,
        ])
            ->assertOk()
            ->assertSee('tracked competitors');

        $this->assertDatabaseHas('tracked_accounts', [
            'user_id' => $user->id,
            'platform' => 'instagram',
            'handle' => 'keepme',
            'kind' => TrackedAccountKind::Competitor->value,
        ]);
        $this->assertDatabaseMissing('tracked_accounts', [
            'user_id' => $user->id,
            'handle' => 'skipme',
        ]);

        $pruned = Cache::get(SuggestCompetitorsJob::cacheKeyFor($user->id, $suggestId));
        $this->assertIsArray($pruned);
        $handles = collect($pruned['suggestions'] ?? [])->pluck('handle')->all();
        $this->assertSame(['skipme'], $handles);

        Queue::assertPushed(SyncTrackedAccountJob::class);
        $this->assertSame(1, TrackedAccount::query()->where('user_id', $user->id)->count());
    }

    public function test_confirm_prunes_even_when_latest_pointer_missing(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $suggestId = (string) Str::uuid();
        Cache::put(SuggestCompetitorsJob::cacheKeyFor($user->id, $suggestId), [
            'status' => 'completed',
            'suggestions' => [
                [
                    'platform' => 'instagram',
                    'handle' => 'KeepMe',
                    'display_name' => 'Keep Me',
                ],
                [
                    'platform' => 'tiktok',
                    'handle' => 'skipme',
                    'display_name' => 'Skip Me',
                ],
            ],
            'error' => null,
        ], now()->addHours(2));

        SnitchServer::tool(ConfirmCompetitorSuggestionsTool::class, [
            'suggest_id' => $suggestId,
            'handles' => ['keepme'],
            'sync' => false,
        ])->assertOk();

        $pruned = Cache::get(SuggestCompetitorsJob::cacheKeyFor($user->id, $suggestId));
        $this->assertSame(['skipme'], collect($pruned['suggestions'] ?? [])->pluck('handle')->all());
        $this->assertSame($suggestId, Cache::get(SuggestCompetitorsJob::latestCacheKeyFor($user->id)));
    }

    public function test_confirm_dismiss_remainder_clears_pending_panel(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $suggestId = (string) Str::uuid();
        Cache::put(SuggestCompetitorsJob::cacheKeyFor($user->id, $suggestId), [
            'status' => 'completed',
            'suggestions' => [
                [
                    'platform' => 'instagram',
                    'handle' => 'keepme',
                ],
                [
                    'platform' => 'tiktok',
                    'handle' => 'skipme',
                ],
            ],
            'error' => null,
        ], now()->addHours(2));
        Cache::put(SuggestCompetitorsJob::latestCacheKeyFor($user->id), $suggestId, now()->addHours(2));
        Cache::put(SuggestCompetitorsJob::activeCacheKeyFor($user->id), $suggestId, now()->addHours(2));

        SnitchServer::tool(ConfirmCompetitorSuggestionsTool::class, [
            'suggest_id' => $suggestId,
            'handles' => ['keepme'],
            'sync' => false,
            'dismiss_remainder' => true,
        ])
            ->assertOk()
            ->assertSee('Pending suggestion panel is clear');

        $this->assertNull(Cache::get(SuggestCompetitorsJob::cacheKeyFor($user->id, $suggestId)));
        $this->assertNull(Cache::get(SuggestCompetitorsJob::latestCacheKeyFor($user->id)));
        $this->assertNull(Cache::get(SuggestCompetitorsJob::activeCacheKeyFor($user->id)));
        $this->assertDatabaseHas('tracked_accounts', [
            'user_id' => $user->id,
            'handle' => 'keepme',
        ]);
        $this->assertDatabaseMissing('tracked_accounts', [
            'user_id' => $user->id,
            'handle' => 'skipme',
        ]);
    }

    public function test_dismiss_clears_active_and_latest_pointers(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $suggestId = (string) Str::uuid();
        Cache::put(SuggestCompetitorsJob::cacheKeyFor($user->id, $suggestId), [
            'status' => 'completed',
            'suggestions' => [['platform' => 'instagram', 'handle' => 'rival']],
            'error' => null,
        ], now()->addHours(2));
        Cache::put(SuggestCompetitorsJob::latestCacheKeyFor($user->id), $suggestId, now()->addHours(2));
        Cache::put(SuggestCompetitorsJob::activeCacheKeyFor($user->id), $suggestId, now()->addHours(2));

        SnitchServer::tool(DismissCompetitorSuggestionsTool::class, [
            'suggest_id' => $suggestId,
        ])->assertOk();

        $this->assertNull(Cache::get(SuggestCompetitorsJob::cacheKeyFor($user->id, $suggestId)));
        $this->assertNull(Cache::get(SuggestCompetitorsJob::latestCacheKeyFor($user->id)));
        $this->assertNull(Cache::get(SuggestCompetitorsJob::activeCacheKeyFor($user->id)));
    }
}
