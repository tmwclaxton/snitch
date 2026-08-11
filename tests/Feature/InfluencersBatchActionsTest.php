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
use Tests\Concerns\WithPlatformBilling;
use Tests\TestCase;

class InfluencersBatchActionsTest extends TestCase
{
    use RefreshDatabase;
    use WithPlatformBilling;

    public function test_keep_many_creates_accounts_and_marks_decisions(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        BrandProfile::factory()->for($user)->create();
        $this->enablePlatformBilling($user);
        $runId = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';

        Cache::put(FindInfluencersJob::cacheKeyFor($user->id, $runId), [
            'status' => 'completed',
            'filters' => [
                'platforms' => ['instagram'],
                'language' => 'English',
                'min_followers' => null,
                'max_followers' => null,
                'brief' => 'Find creators',
            ],
            'brief' => 'Find creators',
            'suggestions' => [
                [
                    'platform' => 'instagram',
                    'handle' => 'keepone',
                    'url' => 'https://www.instagram.com/keepone/',
                    'display_name' => 'Keep One',
                    'avatar' => null,
                    'followers' => 12000,
                    'fit_reason' => 'Strong sneaker fit.',
                ],
                [
                    'platform' => 'instagram',
                    'handle' => 'keeptwo',
                    'url' => 'https://www.instagram.com/keeptwo/',
                    'display_name' => 'Keep Two',
                    'avatar' => null,
                    'followers' => 18000,
                    'fit_reason' => 'Streetwear audience.',
                ],
                [
                    'platform' => 'instagram',
                    'handle' => 'skipme',
                    'url' => 'https://www.instagram.com/skipme/',
                    'display_name' => 'Skip Me',
                    'avatar' => null,
                    'followers' => 9000,
                ],
            ],
            'decisions' => [],
            'error' => null,
        ], now()->addHours(2));
        Cache::put(FindInfluencersJob::latestCacheKeyFor($user->id), $runId, now()->addHours(2));

        $this->actingAs($user)
            ->post(route('influencers.keep-many'), [
                'run_id' => $runId,
                'suggestions' => [
                    ['platform' => 'instagram', 'handle' => 'keepone'],
                    ['platform' => 'instagram', 'handle' => 'keeptwo'],
                ],
            ])
            ->assertRedirect(route('influencers.index'));

        $this->assertDatabaseHas('tracked_accounts', [
            'user_id' => $user->id,
            'platform' => 'instagram',
            'handle' => 'keepone',
            'fit_reason' => 'Strong sneaker fit.',
        ]);
        $this->assertDatabaseHas('tracked_accounts', [
            'user_id' => $user->id,
            'platform' => 'instagram',
            'handle' => 'keeptwo',
            'fit_reason' => 'Streetwear audience.',
        ]);
        $this->assertDatabaseMissing('tracked_accounts', [
            'user_id' => $user->id,
            'handle' => 'skipme',
        ]);

        Queue::assertPushed(SyncTrackedAccountJob::class, 2);

        $payload = Cache::get(FindInfluencersJob::cacheKeyFor($user->id, $runId));
        $this->assertSame('kept', $payload['decisions']['instagram:keepone'] ?? null);
        $this->assertSame('kept', $payload['decisions']['instagram:keeptwo'] ?? null);
        $this->assertArrayNotHasKey('instagram:skipme', $payload['decisions'] ?? []);
    }

    public function test_discard_many_marks_decisions_without_tracking(): void
    {
        $user = User::factory()->create();
        BrandProfile::factory()->for($user)->create();
        $runId = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';

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
                    'handle' => 'goneone',
                    'url' => 'https://www.tiktok.com/@goneone',
                    'display_name' => 'Gone One',
                    'avatar' => null,
                    'followers' => 5000,
                ],
                [
                    'platform' => 'tiktok',
                    'handle' => 'gonetwo',
                    'url' => 'https://www.tiktok.com/@gonetwo',
                    'display_name' => 'Gone Two',
                    'avatar' => null,
                    'followers' => 7000,
                ],
            ],
            'decisions' => [],
            'error' => null,
        ], now()->addHours(2));
        Cache::put(FindInfluencersJob::latestCacheKeyFor($user->id), $runId, now()->addHours(2));

        $this->actingAs($user)
            ->post(route('influencers.discard-many'), [
                'run_id' => $runId,
                'suggestions' => [
                    ['platform' => 'tiktok', 'handle' => 'goneone'],
                    ['platform' => 'tiktok', 'handle' => 'gonetwo'],
                ],
            ])
            ->assertRedirect(route('influencers.index'));

        $this->assertDatabaseMissing('tracked_accounts', [
            'user_id' => $user->id,
            'handle' => 'goneone',
        ]);
        $this->assertDatabaseMissing('tracked_accounts', [
            'user_id' => $user->id,
            'handle' => 'gonetwo',
        ]);

        $payload = Cache::get(FindInfluencersJob::cacheKeyFor($user->id, $runId));
        $this->assertSame('discarded', $payload['decisions']['tiktok:goneone'] ?? null);
        $this->assertSame('discarded', $payload['decisions']['tiktok:gonetwo'] ?? null);
    }

    public function test_batch_destroy_removes_only_owned_influencers(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        BrandProfile::factory()->for($user)->create();

        $keep = TrackedAccount::factory()->influencer()->for($user)->create(['handle' => 'stay']);
        $removeA = TrackedAccount::factory()->influencer()->for($user)->create(['handle' => 'dropa']);
        $removeB = TrackedAccount::factory()->influencer()->for($user)->create(['handle' => 'dropb']);
        $competitor = TrackedAccount::factory()->competitor()->for($user)->create(['handle' => 'rival']);
        $foreign = TrackedAccount::factory()->influencer()->for($other)->create(['handle' => 'foreign']);

        $this->actingAs($user)
            ->post(route('influencers.batch-destroy'), [
                'ids' => [$removeA->id, $removeB->id, $competitor->id, $foreign->id],
            ])
            ->assertRedirect(route('influencers.index'));

        $this->assertDatabaseHas('tracked_accounts', ['id' => $keep->id]);
        $this->assertDatabaseMissing('tracked_accounts', ['id' => $removeA->id]);
        $this->assertDatabaseMissing('tracked_accounts', ['id' => $removeB->id]);
        $this->assertDatabaseHas('tracked_accounts', ['id' => $competitor->id]);
        $this->assertDatabaseHas('tracked_accounts', ['id' => $foreign->id]);
    }

    public function test_influencers_page_exposes_kept_profile_urls(): void
    {
        $user = User::factory()->create();
        BrandProfile::factory()->for($user)->create();
        TrackedAccount::factory()->influencer()->for($user)->create([
            'handle' => 'urlme',
            'url' => 'https://www.instagram.com/urlme/',
            'fit_reason' => 'Good brand-deal fit.',
        ]);

        $this->actingAs($user)
            ->get(route('influencers.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('influencers/Index')
                ->missing('keptAccounts')
                ->loadDeferredProps('default', fn (Assert $page) => $page
                    ->has('keptAccounts', 1)
                    ->where('keptAccounts.0.url', 'https://www.instagram.com/urlme/')
                    ->where('keptAccounts.0.fit_reason', 'Good brand-deal fit.')
                )
            );
    }
}
