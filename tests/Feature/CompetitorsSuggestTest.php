<?php

namespace Tests\Feature;

use App\Jobs\SuggestCompetitorsJob;
use App\Jobs\SyncTrackedAccountJob;
use App\Models\BrandProfile;
use App\Models\TrackedAccount;
use App\Models\User;
use App\Services\Competitors\CompetitorSuggestionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CompetitorsSuggestTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_start_suggest(): void
    {
        $this->postJson(route('competitors.suggest'))
            ->assertUnauthorized();
    }

    public function test_user_can_start_suggest_job(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        BrandProfile::factory()->for($user)->create();

        $response = $this->actingAs($user)
            ->postJson(route('competitors.suggest'))
            ->assertAccepted()
            ->assertJsonStructure(['id', 'status']);

        $suggestId = $response->json('id');

        $this->assertIsString($suggestId);
        Queue::assertPushed(SuggestCompetitorsJob::class, function (SuggestCompetitorsJob $job) use ($user, $suggestId): bool {
            return $job->userId === $user->id && $job->suggestId === $suggestId;
        });

        $this->assertSame(
            'pending',
            Cache::get(SuggestCompetitorsJob::cacheKeyFor($user->id, $suggestId))['status'],
        );
        $this->assertSame(
            $suggestId,
            Cache::get(SuggestCompetitorsJob::activeCacheKeyFor($user->id)),
        );
    }

    public function test_competitors_index_includes_active_suggest_run(): void
    {
        $user = User::factory()->create();
        BrandProfile::factory()->for($user)->create();
        $suggestId = '33333333-3333-4333-8333-333333333333';

        Cache::put(SuggestCompetitorsJob::cacheKeyFor($user->id, $suggestId), [
            'status' => 'processing',
            'suggestions' => null,
            'error' => null,
        ], now()->addMinutes(15));
        Cache::put(SuggestCompetitorsJob::activeCacheKeyFor($user->id), $suggestId, now()->addMinutes(15));

        $this->actingAs($user)
            ->get(route('competitors.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('competitors/Index')
                ->where('suggestRun', [
                    'id' => $suggestId,
                    'status' => 'processing',
                ])
            );
    }

    public function test_competitors_index_clears_stale_completed_active_suggest(): void
    {
        $user = User::factory()->create();
        BrandProfile::factory()->for($user)->create();
        $suggestId = '44444444-4444-4444-8444-444444444444';

        Cache::put(SuggestCompetitorsJob::cacheKeyFor($user->id, $suggestId), [
            'status' => 'completed',
            'suggestions' => [],
            'error' => null,
        ], now()->addMinutes(15));
        Cache::put(SuggestCompetitorsJob::activeCacheKeyFor($user->id), $suggestId, now()->addMinutes(15));

        $this->actingAs($user)
            ->get(route('competitors.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('competitors/Index')
                ->where('suggestRun', null)
            );

        $this->assertNull(Cache::get(SuggestCompetitorsJob::activeCacheKeyFor($user->id)));
    }

    public function test_suggest_requires_brand_profile(): void
    {
        Queue::fake();

        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson(route('competitors.suggest'))
            ->assertRedirect();

        Queue::assertNothingPushed();
    }

    public function test_suggest_status_returns_completed_suggestions(): void
    {
        $user = User::factory()->create();
        BrandProfile::factory()->for($user)->create();
        $suggestId = '11111111-1111-4111-8111-111111111111';

        Cache::put(SuggestCompetitorsJob::cacheKeyFor($user->id, $suggestId), [
            'status' => 'completed',
            'suggestions' => [
                [
                    'platform' => 'instagram',
                    'handle' => 'instrumentl',
                    'url' => 'https://instagram.com/instrumentl',
                    'display_name' => 'Instrumentl',
                    'avatar' => 'https://cdn.example.com/a.jpg',
                ],
            ],
            'error' => null,
        ], now()->addMinutes(15));

        $this->actingAs($user)
            ->getJson(route('competitors.suggest.status', $suggestId))
            ->assertOk()
            ->assertJsonPath('status', 'completed')
            ->assertJsonPath('suggestions.0.handle', 'instrumentl')
            ->assertJsonPath('suggestions.0.display_name', 'Instrumentl');
    }

    public function test_suggest_job_stores_verified_suggestions(): void
    {
        config([
            'snitch.firecrawl.api_key' => 'test-key',
            'snitch.firecrawl.base_url' => 'https://api.firecrawl.test/v1',
            'snitch.nanogpt.api_key' => 'test-key',
            'snitch.nanogpt.base_url' => 'https://nano-gpt.test/api/v1',
            'snitch.apify.token' => 'apify-test',
            'snitch.apify.base_url' => 'https://api.apify.test/v2',
            'snitch.competitor_suggest.platforms' => ['instagram'],
            'snitch.competitor_suggest.min_suggestions' => 1,
            'snitch.competitor_suggest.max_suggestions' => 8,
        ]);

        $orgs = [];

        foreach (range(1, 6) as $n) {
            $orgs[] = [
                'name' => "Rival Bakery {$n}",
                'handles' => [
                    'instagram' => "rivalbakery{$n}",
                    'tiktok' => null,
                    'facebook' => null,
                    'linkedin' => null,
                ],
            ];
        }

        Http::preventStrayRequests();
        Http::fake([
            'https://api.firecrawl.test/v1/search' => Http::response([
                'success' => true,
                'data' => [
                    [
                        'url' => 'https://rivalbakery.example',
                        'title' => 'Rival Bakery',
                        'description' => 'A competing bakery brand.',
                    ],
                ],
            ]),
            'https://nano-gpt.test/api/v1/chat/completions' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'content' => json_encode([
                                'competitors' => $orgs,
                            ], JSON_THROW_ON_ERROR),
                        ],
                    ],
                ],
            ]),
            'https://api.apify.test/v2/acts/*' => Http::response([
                [
                    'ownerUsername' => 'rivalbakery1',
                    'ownerId' => '99',
                    'ownerFullName' => 'Rival Bakery 1',
                    'ownerProfilePicUrl' => 'https://cdn.example.com/rival.jpg',
                    'id' => 'ig_1',
                    'url' => 'https://www.instagram.com/p/ABC123/',
                ],
            ]),
        ]);

        $user = User::factory()->create();
        BrandProfile::factory()->for($user)->create([
            'name' => 'Loaf Local',
            'description' => 'Neighborhood bakery',
            'website' => 'https://loaf.example',
            'own_handles' => [],
        ]);
        $suggestId = '22222222-2222-4222-8222-222222222222';

        Cache::put(SuggestCompetitorsJob::cacheKeyFor($user->id, $suggestId), [
            'status' => 'pending',
            'suggestions' => null,
            'error' => null,
        ], now()->addMinutes(15));
        Cache::put(SuggestCompetitorsJob::activeCacheKeyFor($user->id), $suggestId, now()->addMinutes(15));

        (new SuggestCompetitorsJob($user->id, $suggestId))
            ->handle(app(CompetitorSuggestionService::class));

        $payload = Cache::get(SuggestCompetitorsJob::cacheKeyFor($user->id, $suggestId));

        $this->assertSame('completed', $payload['status']);
        $this->assertGreaterThanOrEqual(1, count($payload['suggestions']));
        $this->assertSame('rivalbakery1', $payload['suggestions'][0]['handle']);
        $this->assertSame('Rival Bakery 1', $payload['suggestions'][0]['display_name']);
        $this->assertNotNull($payload['suggestions'][0]['avatar']);
        $this->assertNull(Cache::get(SuggestCompetitorsJob::activeCacheKeyFor($user->id)));
        $this->assertSame($suggestId, Cache::get(SuggestCompetitorsJob::latestCacheKeyFor($user->id)));
    }

    public function test_competitors_index_persists_completed_suggestions(): void
    {
        $user = User::factory()->create();
        BrandProfile::factory()->for($user)->create();
        $suggestId = '66666666-6666-4666-8666-666666666666';

        Cache::put(SuggestCompetitorsJob::cacheKeyFor($user->id, $suggestId), [
            'status' => 'completed',
            'suggestions' => [
                [
                    'platform' => 'instagram',
                    'handle' => 'instrumentl',
                    'url' => 'https://instagram.com/instrumentl',
                    'display_name' => 'Instrumentl',
                    'avatar' => 'https://cdn.example.com/a.jpg',
                    'source' => 'Grant discovery',
                ],
            ],
            'error' => null,
        ], now()->addHours(2));
        Cache::put(SuggestCompetitorsJob::latestCacheKeyFor($user->id), $suggestId, now()->addHours(2));

        $this->actingAs($user)
            ->get(route('competitors.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('competitors/Index')
                ->has('suggestions', 1)
                ->where('suggestions.0.handle', 'instrumentl')
                ->where('suggestRun', null)
            );
    }

    public function test_dismiss_suggestions_clears_latest(): void
    {
        $user = User::factory()->create();
        BrandProfile::factory()->for($user)->create();
        $suggestId = '77777777-7777-4777-8777-777777777777';

        Cache::put(SuggestCompetitorsJob::cacheKeyFor($user->id, $suggestId), [
            'status' => 'completed',
            'suggestions' => [
                [
                    'platform' => 'instagram',
                    'handle' => 'instrumentl',
                    'url' => 'https://instagram.com/instrumentl',
                    'display_name' => 'Instrumentl',
                    'avatar' => null,
                    'source' => null,
                ],
            ],
            'error' => null,
        ], now()->addHours(2));
        Cache::put(SuggestCompetitorsJob::latestCacheKeyFor($user->id), $suggestId, now()->addHours(2));

        $this->actingAs($user)
            ->post(route('competitors.dismiss-suggestions'))
            ->assertRedirect(route('competitors.index'));

        $this->assertNull(Cache::get(SuggestCompetitorsJob::latestCacheKeyFor($user->id)));
    }

    public function test_suggest_job_clears_active_pointer_on_failure(): void
    {
        $user = User::factory()->create();
        $suggestId = '55555555-5555-4555-8555-555555555555';

        Cache::put(SuggestCompetitorsJob::cacheKeyFor($user->id, $suggestId), [
            'status' => 'processing',
            'suggestions' => [
                [
                    'platform' => 'instagram',
                    'handle' => 'partialrival',
                    'url' => 'https://instagram.com/partialrival',
                    'display_name' => 'Partial Rival',
                    'avatar' => null,
                    'source' => null,
                ],
            ],
            'error' => null,
        ], now()->addMinutes(15));
        Cache::put(SuggestCompetitorsJob::activeCacheKeyFor($user->id), $suggestId, now()->addMinutes(15));

        (new SuggestCompetitorsJob($user->id, $suggestId))
            ->failed(new \RuntimeException('Brand profile not found.'));

        $payload = Cache::get(SuggestCompetitorsJob::cacheKeyFor($user->id, $suggestId));

        $this->assertSame('failed', $payload['status']);
        $this->assertSame('partialrival', $payload['suggestions'][0]['handle']);
        $this->assertNull(Cache::get(SuggestCompetitorsJob::activeCacheKeyFor($user->id)));
    }

    public function test_suggest_job_keeps_partials_when_under_min_suggestions(): void
    {
        config([
            'snitch.firecrawl.api_key' => 'test-key',
            'snitch.firecrawl.base_url' => 'https://api.firecrawl.test/v1',
            'snitch.nanogpt.api_key' => 'test-key',
            'snitch.nanogpt.base_url' => 'https://nano-gpt.test/api/v1',
            'snitch.apify.token' => 'test-token',
            'snitch.apify.base_url' => 'https://api.apify.test/v2',
            'snitch.competitor_suggest.min_suggestions' => 6,
            'snitch.competitor_suggest.max_suggestions' => 16,
            'snitch.competitor_suggest.platforms' => ['instagram'],
            'snitch.competitor_suggest.resolve_concurrency' => 2,
        ]);

        Http::preventStrayRequests();
        Http::fake([
            'https://api.firecrawl.test/v1/search' => Http::response([
                'success' => true,
                'data' => [
                    [
                        'url' => 'https://instagram.com/onlyone',
                        'title' => 'Only One',
                        'description' => 'A single rival.',
                    ],
                ],
            ]),
            'https://nano-gpt.test/api/v1/chat/completions' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'content' => json_encode([
                                'competitors' => [
                                    [
                                        'name' => 'Only One',
                                        'handles' => ['instagram' => 'onlyone'],
                                    ],
                                ],
                            ], JSON_THROW_ON_ERROR),
                        ],
                    ],
                ],
            ]),
            'https://api.apify.test/v2/acts/*' => Http::response([
                [
                    'ownerUsername' => 'onlyone',
                    'ownerId' => '1',
                    'ownerFullName' => 'Only One',
                    'id' => 'ig_1',
                    'url' => 'https://www.instagram.com/p/ABC/',
                ],
            ]),
        ]);

        $user = User::factory()->create();
        BrandProfile::factory()->for($user)->create([
            'name' => 'Loaf Local',
            'description' => 'Neighborhood bakery',
            'website' => 'https://loaf.example',
            'own_handles' => [],
        ]);
        $suggestId = '99999999-9999-4999-8999-999999999999';

        Cache::put(SuggestCompetitorsJob::cacheKeyFor($user->id, $suggestId), [
            'status' => 'pending',
            'suggestions' => null,
            'error' => null,
        ], now()->addMinutes(15));
        Cache::put(SuggestCompetitorsJob::activeCacheKeyFor($user->id), $suggestId, now()->addMinutes(15));

        (new SuggestCompetitorsJob($user->id, $suggestId))
            ->handle(app(CompetitorSuggestionService::class));

        $payload = Cache::get(SuggestCompetitorsJob::cacheKeyFor($user->id, $suggestId));

        $this->assertSame('failed', $payload['status']);
        $this->assertStringContainsString('need at least 6', (string) $payload['error']);
        $this->assertCount(1, $payload['suggestions']);
        $this->assertSame('onlyone', $payload['suggestions'][0]['handle']);
        $this->assertNull(Cache::get(SuggestCompetitorsJob::activeCacheKeyFor($user->id)));
        $this->assertNull(Cache::get(SuggestCompetitorsJob::latestCacheKeyFor($user->id)));
    }

    public function test_confirm_suggestions_still_creates_tracked_accounts(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        BrandProfile::factory()->for($user)->create();

        $this->actingAs($user)
            ->post(route('competitors.confirm-suggestions'), [
                'suggestions' => [
                    [
                        'platform' => 'instagram',
                        'handle' => 'rivalbakery',
                        'display_name' => 'Rival Bakery',
                        'avatar' => 'https://cdn.example.com/rival.jpg',
                    ],
                ],
            ])
            ->assertRedirect(route('competitors.index'));

        $this->assertDatabaseHas('tracked_accounts', [
            'user_id' => $user->id,
            'handle' => 'rivalbakery',
            'platform' => 'instagram',
            'display_name' => 'Rival Bakery',
        ]);
        Queue::assertPushed(SyncTrackedAccountJob::class);
    }

    public function test_confirm_suggestions_prunes_confirmed_rows_and_keeps_others(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        BrandProfile::factory()->for($user)->create();
        $suggestId = '88888888-8888-4888-8888-888888888888';

        Cache::put(SuggestCompetitorsJob::cacheKeyFor($user->id, $suggestId), [
            'status' => 'completed',
            'suggestions' => [
                [
                    'platform' => 'instagram',
                    'handle' => 'instrumentl',
                    'url' => 'https://instagram.com/instrumentl',
                    'display_name' => 'Instrumentl',
                    'avatar' => null,
                    'source' => null,
                ],
                [
                    'platform' => 'facebook',
                    'handle' => 'GrantWatch',
                    'url' => 'https://facebook.com/GrantWatch',
                    'display_name' => 'GrantWatch',
                    'avatar' => null,
                    'source' => null,
                ],
                [
                    'platform' => 'linkedin',
                    'handle' => 'candid-org',
                    'url' => 'https://linkedin.com/company/candid-org',
                    'display_name' => 'Candid',
                    'avatar' => null,
                    'source' => null,
                ],
            ],
            'error' => null,
        ], now()->addHours(2));
        Cache::put(SuggestCompetitorsJob::latestCacheKeyFor($user->id), $suggestId, now()->addHours(2));

        $this->actingAs($user)
            ->post(route('competitors.confirm-suggestions'), [
                'suggestions' => [
                    [
                        'platform' => 'instagram',
                        'handle' => 'instrumentl',
                        'display_name' => 'Instrumentl',
                    ],
                    [
                        'platform' => 'facebook',
                        'handle' => 'GrantWatch',
                        'display_name' => 'GrantWatch',
                    ],
                ],
            ])
            ->assertRedirect(route('competitors.index'));

        $payload = Cache::get(SuggestCompetitorsJob::cacheKeyFor($user->id, $suggestId));

        $this->assertSame('completed', $payload['status']);
        $this->assertCount(1, $payload['suggestions']);
        $this->assertSame('linkedin', $payload['suggestions'][0]['platform']);
        $this->assertSame('candid-org', $payload['suggestions'][0]['handle']);
        $this->assertSame($suggestId, Cache::get(SuggestCompetitorsJob::latestCacheKeyFor($user->id)));

        $this->actingAs($user)
            ->get(route('competitors.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('competitors/Index')
                ->has('suggestions', 1)
                ->where('suggestions.0.handle', 'candid-org')
            );
    }

    public function test_index_filters_already_tracked_suggestions_on_load(): void
    {
        $user = User::factory()->create();
        BrandProfile::factory()->for($user)->create();
        $suggestId = '99999999-9999-4999-8999-999999999999';

        TrackedAccount::factory()->for($user)->create([
            'platform' => 'instagram',
            'handle' => 'instrumentl',
        ]);

        Cache::put(SuggestCompetitorsJob::cacheKeyFor($user->id, $suggestId), [
            'status' => 'completed',
            'suggestions' => [
                [
                    'platform' => 'instagram',
                    'handle' => 'instrumentl',
                    'url' => 'https://instagram.com/instrumentl',
                    'display_name' => 'Instrumentl',
                    'avatar' => null,
                    'source' => null,
                ],
                [
                    'platform' => 'tiktok',
                    'handle' => 'grantsforgood',
                    'url' => 'https://tiktok.com/@grantsforgood',
                    'display_name' => 'Grants for Good',
                    'avatar' => null,
                    'source' => null,
                ],
            ],
            'error' => null,
        ], now()->addHours(2));
        Cache::put(SuggestCompetitorsJob::latestCacheKeyFor($user->id), $suggestId, now()->addHours(2));

        $this->actingAs($user)
            ->get(route('competitors.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('competitors/Index')
                ->has('suggestions', 1)
                ->where('suggestions.0.handle', 'grantsforgood')
            );

        $payload = Cache::get(SuggestCompetitorsJob::cacheKeyFor($user->id, $suggestId));
        $this->assertCount(1, $payload['suggestions']);
        $this->assertSame('grantsforgood', $payload['suggestions'][0]['handle']);
    }

    public function test_competitors_page_has_async_suggest_table_ux(): void
    {
        $page = file_get_contents(resource_path('js/pages/competitors/Index.vue'));

        $this->assertNotFalse($page);
        $this->assertStringContainsString('Suggest competitors', $page);
        $this->assertStringContainsString('Finding…', $page);
        $this->assertStringContainsString('suggestStatus.url', $page);
        $this->assertStringContainsString('Scraping the neighborhood', $page);
        $this->assertStringContainsString('Searching the web for rivals', $page);
        $this->assertStringContainsString('Verified picks appear below as they land', $page);
        $this->assertStringContainsString('Found ${localSuggestions.value.length} so far', $page);
        $this->assertStringContainsString('applySuggestionRows', $page);
        $this->assertStringContainsString('attempt > 200', $page);
        $this->assertStringContainsString('suggestRun', $page);
        $this->assertStringContainsString('onMounted', $page);
        $this->assertStringContainsString('platformIconSrc', $page);
        $this->assertStringContainsString('platformLabel', $page);
        $this->assertStringContainsString('data-platform', $page);
        $this->assertStringContainsString('competitorShow.url', $page);
        $this->assertStringContainsString('@click="askRemove(account)"', $page);
        $this->assertStringContainsString('RemoveCompetitorModal', $page);
        $this->assertStringContainsString('Last synced', $page);
        $this->assertStringContainsString('Auto sync', $page);
        $this->assertStringContainsString('<table', $page);
        $this->assertStringContainsString('min-w-0 overflow-x-auto', $page);
        $this->assertStringContainsString('sm:table-cell', $page);
        $this->assertStringContainsString('md:table-cell', $page);
        $this->assertStringContainsString('lg:table-cell', $page);
        $this->assertStringNotContainsString('min-w-[48rem]', $page);
        $this->assertStringNotContainsString('min-w-[36rem]', $page);
        $this->assertStringNotContainsString('snitch-cutout-platform-mark', $page);
        $this->assertStringNotContainsString('snitch-polaroid', $page);
        $this->assertStringNotContainsString('Sync now', $page);
        $this->assertStringNotContainsString('Profile', $page);
        $this->assertStringContainsString('Suggested rivals', $page);
        $this->assertStringContainsString('Select all', $page);
        $this->assertStringContainsString('dismissSuggestions', $page);
        $this->assertStringContainsString('withoutTracked', $page);
        $this->assertStringContainsString('onSuccess', $page);
    }
}
