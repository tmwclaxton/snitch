<?php

namespace Tests\Feature;

use App\Jobs\SuggestCompetitorsJob;
use App\Jobs\SyncTrackedAccountJob;
use App\Models\BrandProfile;
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
            'snitch.nanogpt.api_key' => 'test-key',
            'snitch.nanogpt.base_url' => 'https://nano-gpt.test/api/v1',
            'snitch.apify.token' => 'apify-test',
            'snitch.apify.base_url' => 'https://api.apify.test/v2',
        ]);

        Http::preventStrayRequests();
        Http::fake([
            'https://nano-gpt.test/api/v1/chat/completions' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'content' => json_encode([
                                'competitors' => [
                                    [
                                        'name' => 'Rival Bakery',
                                        'handles' => [
                                            'instagram' => 'rivalbakery',
                                            'tiktok' => null,
                                            'facebook' => null,
                                            'linkedin' => null,
                                        ],
                                    ],
                                ],
                            ], JSON_THROW_ON_ERROR),
                        ],
                    ],
                ],
            ]),
            'https://api.apify.test/v2/acts/*' => Http::response([
                [
                    'ownerUsername' => 'rivalbakery',
                    'ownerId' => '99',
                    'ownerFullName' => 'Rival Bakery',
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
        $this->assertCount(1, $payload['suggestions']);
        $this->assertSame('rivalbakery', $payload['suggestions'][0]['handle']);
        $this->assertSame('Rival Bakery', $payload['suggestions'][0]['display_name']);
        $this->assertNotNull($payload['suggestions'][0]['avatar']);
        $this->assertNull(Cache::get(SuggestCompetitorsJob::activeCacheKeyFor($user->id)));
    }

    public function test_suggest_job_clears_active_pointer_on_failure(): void
    {
        $user = User::factory()->create();
        $suggestId = '55555555-5555-4555-8555-555555555555';

        Cache::put(SuggestCompetitorsJob::cacheKeyFor($user->id, $suggestId), [
            'status' => 'processing',
            'suggestions' => null,
            'error' => null,
        ], now()->addMinutes(15));
        Cache::put(SuggestCompetitorsJob::activeCacheKeyFor($user->id), $suggestId, now()->addMinutes(15));

        (new SuggestCompetitorsJob($user->id, $suggestId))
            ->failed(new \RuntimeException('Brand profile not found.'));

        $payload = Cache::get(SuggestCompetitorsJob::cacheKeyFor($user->id, $suggestId));

        $this->assertSame('failed', $payload['status']);
        $this->assertNull(Cache::get(SuggestCompetitorsJob::activeCacheKeyFor($user->id)));
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

    public function test_competitors_page_has_async_suggest_ux(): void
    {
        $page = file_get_contents(resource_path('js/pages/competitors/Index.vue'));

        $this->assertNotFalse($page);
        $this->assertStringContainsString('Suggest competitors', $page);
        $this->assertStringContainsString('Finding…', $page);
        $this->assertStringContainsString('suggestStatus.url', $page);
        $this->assertStringContainsString('Scraping the neighborhood', $page);
        $this->assertStringContainsString('suggestRun', $page);
        $this->assertStringContainsString('onMounted', $page);
        $this->assertStringContainsString('platformIconSrc', $page);
        $this->assertStringContainsString('platformLabel', $page);
        $this->assertStringContainsString('data-platform', $page);
        $this->assertStringContainsString('snitch-cutout-platform-mark', $page);
    }
}
