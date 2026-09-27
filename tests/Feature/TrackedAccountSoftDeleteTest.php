<?php

namespace Tests\Feature;

use App\Enums\AnalysisStatus;
use App\Enums\Platform;
use App\Enums\PostType;
use App\Enums\TrackedAccountKind;
use App\Jobs\SyncTrackedAccountJob;
use App\Models\BrandProfile;
use App\Models\Post;
use App\Models\PostAnalysis;
use App\Models\SocialAccount;
use App\Models\TrackedAccount;
use App\Models\User;
use App\Services\Tracking\FollowerCountRefresher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\WithPlatformBilling;
use Tests\TestCase;

class TrackedAccountSoftDeleteTest extends TestCase
{
    use RefreshDatabase;
    use WithPlatformBilling;

    public function test_removed_tracker_is_hidden_from_tracking_and_dashboard_but_keeps_posts(): void
    {
        $user = User::factory()->create();
        BrandProfile::factory()->for($user)->create();

        $kept = TrackedAccount::factory()->for($user)->create([
            'platform' => Platform::Instagram,
            'handle' => 'still_here',
            'kind' => TrackedAccountKind::Competitor,
        ]);
        $removed = TrackedAccount::factory()->for($user)->create([
            'platform' => Platform::Instagram,
            'handle' => 'gone_rival',
            'kind' => TrackedAccountKind::Competitor,
        ]);

        $post = Post::factory()->forAccount($removed)->create([
            'type' => PostType::Reel,
            'external_id' => 'soft-delete-reel',
            'posted_at' => now()->subDay(),
            'metrics' => ['likes' => 10, 'comments' => 1],
        ]);
        PostAnalysis::factory()->for($post)->create([
            'status' => AnalysisStatus::Completed,
        ]);

        $socialId = (int) $removed->social_account_id;

        $this->actingAs($user)
            ->delete(route('competitors.destroy', $removed))
            ->assertRedirect(route('competitors.index'));

        $this->assertSoftDeleted('tracked_accounts', ['id' => $removed->id]);
        $this->assertNotSoftDeleted('tracked_accounts', ['id' => $kept->id]);
        $this->assertDatabaseHas('social_accounts', ['id' => $socialId]);
        $this->assertDatabaseHas('posts', [
            'id' => $post->id,
            'social_account_id' => $socialId,
        ]);
        $this->assertDatabaseCount('post_analyses', 1);

        $this->assertFalse(
            Post::query()->forUser($user)->whereKey($post->id)->exists(),
        );

        $this->actingAs($user)
            ->get(route('competitors.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('competitors/Index')
                ->missing('accounts')
                ->loadDeferredProps('default', fn (Assert $page) => $page
                    ->has('accounts', 1)
                    ->where('accounts.0.id', $kept->id)
                )
            );

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard')
                ->has('rivals', 1)
                ->where('rivals.0.handle', 'still_here')
            );

        $this->actingAs($user)
            ->get(route('competitors.show', $removed))
            ->assertNotFound();
    }

    public function test_soft_deleted_trackers_are_excluded_from_sync_and_follower_refresh(): void
    {
        Queue::fake();

        config(['snitch.sync.min_interval_days' => 7]);

        $user = User::factory()->onTrial()->create();
        $this->enablePlatformBilling($user);

        $active = TrackedAccount::factory()->for($user)->create([
            'platform' => Platform::Instagram,
            'last_synced_at' => null,
            'last_sync_status' => null,
        ]);
        $trashed = TrackedAccount::factory()->for($user)->create([
            'platform' => Platform::Instagram,
            'last_synced_at' => null,
            'last_sync_status' => null,
        ]);
        $trashed->delete();

        $this->artisan('snitch:sync-accounts')
            ->expectsOutputToContain('Enqueued 1 account sync jobs (0 skipped recently; 0 over quota; 0 low balance).')
            ->assertSuccessful();

        Queue::assertPushed(SyncTrackedAccountJob::class, 1);
        Queue::assertPushed(
            SyncTrackedAccountJob::class,
            fn (SyncTrackedAccountJob $job): bool => $job->trackedAccountId === $active->id,
        );
        Queue::assertNotPushed(
            SyncTrackedAccountJob::class,
            fn (SyncTrackedAccountJob $job): bool => $job->trackedAccountId === $trashed->id,
        );

        $onlyTrashedSocial = SocialAccount::factory()->forPlatform(Platform::Instagram)->create([
            'handle' => 'only_trashed_tracker',
        ]);
        TrackedAccount::factory()->for($user)->forSocialAccount($onlyTrashedSocial)->create([
            'platform' => Platform::Instagram,
            'followers' => 500,
        ])->delete();

        $dueIds = app(FollowerCountRefresher::class)->dueSocialAccountIds();

        $this->assertContains((int) $active->social_account_id, $dueIds);
        $this->assertNotContains((int) $onlyTrashedSocial->id, $dueIds);
        $this->assertNotContains((int) $trashed->social_account_id, $dueIds);
    }

    public function test_readding_removed_handle_restores_the_same_row(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        BrandProfile::factory()->for($user)->create();
        $this->enablePlatformBilling($user);

        $account = TrackedAccount::factory()->for($user)->create([
            'platform' => Platform::Instagram,
            'handle' => 'comeback_kid',
            'display_name' => 'Old Name',
            'kind' => TrackedAccountKind::Competitor,
        ]);
        $originalId = $account->id;
        $socialId = (int) $account->social_account_id;

        $this->actingAs($user)
            ->delete(route('competitors.destroy', $account))
            ->assertRedirect(route('competitors.index'));

        $this->assertSoftDeleted('tracked_accounts', ['id' => $originalId]);

        $this->actingAs($user)
            ->post(route('competitors.store'), [
                'platform' => 'instagram',
                'handle' => '@comeback_kid',
                'display_name' => 'New Name',
            ])
            ->assertRedirect(route('competitors.index'));

        $restored = TrackedAccount::query()->find($originalId);

        $this->assertNotNull($restored);
        $this->assertNull($restored->deleted_at);
        $this->assertSame($socialId, (int) $restored->social_account_id);
        $this->assertSame('New Name', $restored->display_name);
        $this->assertSame(1, TrackedAccount::withTrashed()
            ->where('user_id', $user->id)
            ->where('handle', 'comeback_kid')
            ->count());
    }
}
