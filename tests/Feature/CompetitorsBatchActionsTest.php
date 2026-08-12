<?php

namespace Tests\Feature;

use App\Jobs\SuggestCompetitorsJob;
use App\Jobs\SyncTrackedAccountJob;
use App\Models\BrandProfile;
use App\Models\TrackedAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\WithPlatformBilling;
use Tests\TestCase;

class CompetitorsBatchActionsTest extends TestCase
{
    use RefreshDatabase;
    use WithPlatformBilling;

    public function test_batch_sync_queues_jobs_for_owned_competitors(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        BrandProfile::factory()->for($user)->create();
        $this->enablePlatformBilling($user);

        $first = TrackedAccount::factory()->for($user)->create(['handle' => 'rival-a']);
        $second = TrackedAccount::factory()->for($user)->create(['handle' => 'rival-b']);
        $other = TrackedAccount::factory()->create(['handle' => 'not-mine']);

        $this->actingAs($user)
            ->post(route('competitors.batch-sync'), [
                'ids' => [$first->id, $second->id, $other->id],
            ])
            ->assertRedirect();

        Queue::assertPushed(SyncTrackedAccountJob::class, 2);
        Queue::assertPushed(SyncTrackedAccountJob::class, function (SyncTrackedAccountJob $job) use ($first): bool {
            return $job->trackedAccountId === $first->id && $job->force === true;
        });
        Queue::assertPushed(SyncTrackedAccountJob::class, function (SyncTrackedAccountJob $job) use ($second): bool {
            return $job->trackedAccountId === $second->id && $job->force === true;
        });

        $this->assertSame('running', $first->fresh()?->last_sync_status);
        $this->assertSame('running', $second->fresh()?->last_sync_status);
        $this->assertNotSame('running', $other->fresh()?->last_sync_status);
    }

    public function test_batch_sync_passes_sync_options_to_jobs(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        BrandProfile::factory()->for($user)->create();
        $this->enablePlatformBilling($user);

        $first = TrackedAccount::factory()->for($user)->create(['handle' => 'rival-a']);

        $this->actingAs($user)
            ->post(route('competitors.batch-sync'), [
                'ids' => [$first->id],
                'posts_limit' => 30,
                'recency_days' => 45,
            ])
            ->assertRedirect();

        Queue::assertPushed(SyncTrackedAccountJob::class, function (SyncTrackedAccountJob $job) use ($first): bool {
            return $job->trackedAccountId === $first->id
                && $job->postsLimit === 30
                && $job->recencyDays === 45;
        });
    }

    public function test_batch_sync_blocked_when_balance_too_low(): void
    {
        Queue::fake();

        $user = User::factory()->withoutStarterCredit()->create();
        BrandProfile::factory()->for($user)->create();
        $account = TrackedAccount::factory()->for($user)->create();

        $this->actingAs($user)
            ->from(route('competitors.index'))
            ->post(route('competitors.batch-sync'), [
                'ids' => [$account->id],
            ])
            ->assertRedirect(route('billing.edit'));

        Queue::assertNothingPushed();
        $this->assertNotSame('running', $account->fresh()?->last_sync_status);
    }

    public function test_batch_destroy_removes_owned_competitors_only(): void
    {
        $user = User::factory()->create();
        BrandProfile::factory()->for($user)->create();

        $first = TrackedAccount::factory()->for($user)->create(['handle' => 'rival-a']);
        $second = TrackedAccount::factory()->for($user)->create(['handle' => 'rival-b']);
        $other = TrackedAccount::factory()->create(['handle' => 'not-mine']);

        $this->actingAs($user)
            ->post(route('competitors.batch-destroy'), [
                'ids' => [$first->id, $second->id, $other->id],
            ])
            ->assertRedirect(route('competitors.index'));

        $this->assertDatabaseMissing('tracked_accounts', ['id' => $first->id]);
        $this->assertDatabaseMissing('tracked_accounts', ['id' => $second->id]);
        $this->assertDatabaseHas('tracked_accounts', ['id' => $other->id]);
    }

    public function test_dismiss_suggestions_can_prune_selected_rows(): void
    {
        $user = User::factory()->create();
        BrandProfile::factory()->for($user)->create();
        $suggestId = '88888888-8888-4888-8888-888888888888';

        Cache::put(SuggestCompetitorsJob::cacheKeyFor($user->id, $suggestId), [
            'status' => 'completed',
            'suggestions' => [
                [
                    'platform' => 'instagram',
                    'handle' => 'keep-me',
                    'url' => 'https://instagram.com/keep-me',
                    'display_name' => 'Keep Me',
                    'avatar' => null,
                    'source' => null,
                ],
                [
                    'platform' => 'tiktok',
                    'handle' => 'drop-me',
                    'url' => 'https://tiktok.com/@drop-me',
                    'display_name' => 'Drop Me',
                    'avatar' => null,
                    'source' => null,
                ],
            ],
            'error' => null,
        ], now()->addHours(2));
        Cache::put(SuggestCompetitorsJob::latestCacheKeyFor($user->id), $suggestId, now()->addHours(2));

        $this->actingAs($user)
            ->post(route('competitors.dismiss-suggestions'), [
                'suggestions' => [
                    [
                        'platform' => 'tiktok',
                        'handle' => 'drop-me',
                    ],
                ],
            ])
            ->assertRedirect(route('competitors.index'));

        $payload = Cache::get(SuggestCompetitorsJob::cacheKeyFor($user->id, $suggestId));
        $this->assertIsArray($payload);
        $this->assertCount(1, $payload['suggestions']);
        $this->assertSame('keep-me', $payload['suggestions'][0]['handle']);
        $this->assertSame($suggestId, Cache::get(SuggestCompetitorsJob::latestCacheKeyFor($user->id)));
    }

    public function test_dismiss_suggestions_without_payload_still_clears_all(): void
    {
        $user = User::factory()->create();
        BrandProfile::factory()->for($user)->create();
        $suggestId = '99999999-9999-4999-8999-999999999999';

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
}
