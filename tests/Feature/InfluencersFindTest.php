<?php

namespace Tests\Feature;

use App\Jobs\FindInfluencersJob;
use App\Jobs\SyncTrackedAccountJob;
use App\Models\BrandProfile;
use App\Models\TrackedAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class InfluencersFindTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_open_influencers_page(): void
    {
        $this->get(route('influencers.index'))
            ->assertRedirect();
    }

    public function test_user_can_view_influencers_page(): void
    {
        $user = User::factory()->create();
        BrandProfile::factory()->for($user)->create(['name' => 'Sneaker Co']);

        $this->actingAs($user)
            ->get(route('influencers.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('influencers/Index')
                ->where('brand.name', 'Sneaker Co')
                ->where('canSearch', true)
                ->where('filters.platform', 'instagram')
                ->has('influencerCap')
            );
    }

    public function test_user_can_start_search_job(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        BrandProfile::factory()->for($user)->create();

        $response = $this->actingAs($user)
            ->postJson(route('influencers.search'), [
                'platform' => 'instagram',
                'language' => 'English',
                'min_followers' => 1000,
                'max_followers' => 50000,
                'brief' => 'Find mid-size fashion creators for a sneaker DTC brand.',
            ])
            ->assertAccepted()
            ->assertJsonStructure(['id', 'status']);

        $runId = $response->json('id');
        $this->assertIsString($runId);

        Queue::assertPushed(FindInfluencersJob::class, function (FindInfluencersJob $job) use ($user, $runId): bool {
            return $job->userId === $user->id
                && $job->runId === $runId
                && $job->filters['brief'] !== '';
        });

        $this->assertSame(
            'pending',
            Cache::get(FindInfluencersJob::cacheKeyFor($user->id, $runId))['status'],
        );
    }

    public function test_search_rejected_while_undecided_suggestions_remain(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        BrandProfile::factory()->for($user)->create();
        $runId = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';

        Cache::put(FindInfluencersJob::cacheKeyFor($user->id, $runId), [
            'status' => 'completed',
            'filters' => [
                'platform' => 'instagram',
                'language' => 'English',
                'min_followers' => null,
                'max_followers' => null,
                'brief' => 'Find creators',
            ],
            'brief' => 'Find creators',
            'suggestions' => [
                [
                    'platform' => 'instagram',
                    'handle' => 'creatorone',
                    'url' => 'https://www.instagram.com/creatorone/',
                    'display_name' => 'Creator One',
                    'avatar' => null,
                    'followers' => 12000,
                ],
            ],
            'decisions' => [],
            'error' => null,
        ], now()->addHours(2));
        Cache::put(FindInfluencersJob::latestCacheKeyFor($user->id), $runId, now()->addHours(2));

        $this->actingAs($user)
            ->postJson(route('influencers.search'), [
                'platform' => 'instagram',
                'language' => 'English',
                'brief' => 'Another search brief that is long enough.',
            ])
            ->assertStatus(409);
    }

    public function test_search_allowed_after_all_suggestions_decided(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        BrandProfile::factory()->for($user)->create();
        $runId = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';

        Cache::put(FindInfluencersJob::cacheKeyFor($user->id, $runId), [
            'status' => 'completed',
            'filters' => [
                'platform' => 'instagram',
                'language' => 'English',
                'min_followers' => null,
                'max_followers' => null,
                'brief' => 'Find creators',
            ],
            'brief' => 'Find creators',
            'suggestions' => [
                [
                    'platform' => 'instagram',
                    'handle' => 'creatorone',
                    'url' => 'https://www.instagram.com/creatorone/',
                    'display_name' => 'Creator One',
                    'avatar' => null,
                    'followers' => 12000,
                ],
            ],
            'decisions' => [
                'instagram:creatorone' => 'discarded',
            ],
            'error' => null,
        ], now()->addHours(2));
        Cache::put(FindInfluencersJob::latestCacheKeyFor($user->id), $runId, now()->addHours(2));

        $this->actingAs($user)
            ->postJson(route('influencers.search'), [
                'platform' => 'instagram',
                'language' => 'English',
                'brief' => 'Another search brief that is long enough.',
            ])
            ->assertAccepted();
    }

    public function test_keep_creates_tracked_account_and_marks_decision(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        BrandProfile::factory()->for($user)->create();
        $runId = 'cccccccc-cccc-4ccc-8ccc-cccccccccccc';

        Cache::put(FindInfluencersJob::cacheKeyFor($user->id, $runId), [
            'status' => 'completed',
            'filters' => [
                'platform' => 'instagram',
                'language' => 'English',
                'min_followers' => null,
                'max_followers' => null,
                'brief' => 'Find creators',
            ],
            'brief' => 'Find creators',
            'suggestions' => [
                [
                    'platform' => 'instagram',
                    'handle' => 'keepme',
                    'url' => 'https://www.instagram.com/keepme/',
                    'display_name' => 'Keep Me',
                    'avatar' => null,
                    'followers' => 22000,
                ],
            ],
            'decisions' => [],
            'error' => null,
        ], now()->addHours(2));
        Cache::put(FindInfluencersJob::latestCacheKeyFor($user->id), $runId, now()->addHours(2));

        $this->actingAs($user)
            ->post(route('influencers.keep'), [
                'platform' => 'instagram',
                'handle' => 'keepme',
                'run_id' => $runId,
            ])
            ->assertRedirect(route('influencers.index'));

        $this->assertDatabaseHas('tracked_accounts', [
            'user_id' => $user->id,
            'platform' => 'instagram',
            'handle' => 'keepme',
            'display_name' => 'Keep Me',
        ]);

        Queue::assertPushed(SyncTrackedAccountJob::class);

        $payload = Cache::get(FindInfluencersJob::cacheKeyFor($user->id, $runId));
        $this->assertSame('kept', $payload['decisions']['instagram:keepme'] ?? null);
    }

    public function test_discard_marks_decision_without_tracking(): void
    {
        $user = User::factory()->create();
        BrandProfile::factory()->for($user)->create();
        $runId = 'dddddddd-dddd-4ddd-8ddd-dddddddddddd';

        Cache::put(FindInfluencersJob::cacheKeyFor($user->id, $runId), [
            'status' => 'completed',
            'filters' => [
                'platforms' => ['tiktok'],
                'language' => 'English',
                'min_followers' => null,
                'max_followers' => null,
                'brief' => 'Find creators',
            ],
            'brief' => 'Find creators',
            'suggestions' => [
                [
                    'platform' => 'tiktok',
                    'handle' => 'skipme',
                    'url' => 'https://www.tiktok.com/@skipme',
                    'display_name' => 'Skip Me',
                    'avatar' => null,
                    'followers' => 8000,
                ],
            ],
            'decisions' => [],
            'error' => null,
        ], now()->addHours(2));
        Cache::put(FindInfluencersJob::latestCacheKeyFor($user->id), $runId, now()->addHours(2));

        $this->actingAs($user)
            ->post(route('influencers.discard'), [
                'platform' => 'tiktok',
                'handle' => 'skipme',
                'run_id' => $runId,
            ])
            ->assertRedirect(route('influencers.index'));

        $this->assertDatabaseMissing('tracked_accounts', [
            'user_id' => $user->id,
            'handle' => 'skipme',
        ]);

        $payload = Cache::get(FindInfluencersJob::cacheKeyFor($user->id, $runId));
        $this->assertSame('discarded', $payload['decisions']['tiktok:skipme'] ?? null);
    }

    public function test_keep_allows_many_influencers_without_seat_caps(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        BrandProfile::factory()->for($user)->create();
        TrackedAccount::factory()->count(12)->influencer()->for($user)->create();
        TrackedAccount::factory()->count(5)->competitor()->for($user)->create();

        $runId = 'eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee';

        Cache::put(FindInfluencersJob::cacheKeyFor($user->id, $runId), [
            'status' => 'completed',
            'filters' => [
                'platforms' => ['youtube'],
                'language' => 'English',
                'min_followers' => null,
                'max_followers' => null,
                'brief' => 'Find creators',
            ],
            'brief' => 'Find creators',
            'suggestions' => [
                [
                    'platform' => 'youtube',
                    'handle' => 'overflow',
                    'url' => 'https://www.youtube.com/@overflow',
                    'display_name' => 'Overflow',
                    'avatar' => null,
                    'followers' => 40000,
                ],
            ],
            'decisions' => [],
            'error' => null,
        ], now()->addHours(2));
        Cache::put(FindInfluencersJob::latestCacheKeyFor($user->id), $runId, now()->addHours(2));

        $this->actingAs($user)
            ->from(route('influencers.index'))
            ->post(route('influencers.keep'), [
                'platform' => 'youtube',
                'handle' => 'overflow',
                'run_id' => $runId,
            ])
            ->assertRedirect(route('influencers.index'));

        $this->assertDatabaseHas('tracked_accounts', [
            'user_id' => $user->id,
            'handle' => 'overflow',
            'kind' => 'influencer',
        ]);
    }

    public function test_search_status_returns_payload(): void
    {
        $user = User::factory()->create();
        BrandProfile::factory()->for($user)->create();
        $runId = 'ffffffff-ffff-4fff-8fff-ffffffffffff';

        Cache::put(FindInfluencersJob::cacheKeyFor($user->id, $runId), [
            'status' => 'processing',
            'filters' => ['platform' => 'instagram', 'brief' => 'x'],
            'brief' => 'x',
            'suggestions' => [
                [
                    'platform' => 'instagram',
                    'handle' => 'partial',
                    'url' => 'https://www.instagram.com/partial/',
                    'display_name' => 'Partial',
                    'avatar' => null,
                    'followers' => null,
                ],
            ],
            'decisions' => [],
            'error' => null,
        ], now()->addMinutes(15));

        $this->actingAs($user)
            ->getJson(route('influencers.search.status', $runId))
            ->assertOk()
            ->assertJsonPath('status', 'processing')
            ->assertJsonPath('suggestions.0.handle', 'partial')
            ->assertJsonPath('review_complete', false);
    }

    public function test_failed_run_with_undecided_partials_allows_new_search(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        BrandProfile::factory()->for($user)->create();
        $runId = 'eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee';

        Cache::put(FindInfluencersJob::cacheKeyFor($user->id, $runId), [
            'status' => 'failed',
            'filters' => [
                'platform' => 'instagram',
                'language' => 'English',
                'min_followers' => 1000,
                'max_followers' => 15000,
                'brief' => 'UK grants creators',
            ],
            'brief' => 'UK grants creators',
            'suggestions' => [
                [
                    'platform' => 'instagram',
                    'handle' => 'thinpartial',
                    'url' => 'https://www.instagram.com/thinpartial/',
                    'display_name' => 'Thin Partial',
                    'avatar' => null,
                    'followers' => 4200,
                ],
            ],
            'decisions' => [],
            'error' => 'Only 1 verified influencer profiles found (need at least 6).',
        ], now()->addHours(2));
        Cache::put(FindInfluencersJob::latestCacheKeyFor($user->id), $runId, now()->addHours(2));

        $this->actingAs($user)
            ->get(route('influencers.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('canSearch', true)
                ->where('latestRun.status', 'failed')
            );

        $this->actingAs($user)
            ->postJson(route('influencers.search'), [
                'platform' => 'instagram',
                'language' => 'English',
                'min_followers' => 1000,
                'max_followers' => 15000,
                'brief' => 'Retry UK grants Instagram creators search.',
            ])
            ->assertAccepted();
    }
}
