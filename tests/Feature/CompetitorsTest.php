<?php

namespace Tests\Feature;

use App\Enums\PostType;
use App\Jobs\SyncTrackedAccountJob;
use App\Models\BrandProfile;
use App\Models\Post;
use App\Models\PostAnalysis;
use App\Models\TrackedAccount;
use App\Models\User;
use App\Models\WinnerInsight;
use App\Services\Billing\UsageBillingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\WithPlatformBilling;
use Tests\TestCase;

class CompetitorsTest extends TestCase
{
    use RefreshDatabase;
    use WithPlatformBilling;

    public function test_snitches_urls_and_legacy_competitors_redirect(): void
    {
        $this->assertSame(url('/snitches'), route('competitors.index'));

        $user = User::factory()->create();
        BrandProfile::factory()->for($user)->create();

        $this->actingAs($user)
            ->get('/competitors')
            ->assertRedirect('/snitches');

        $account = TrackedAccount::factory()->for($user)->create(['handle' => 'legacyredirect']);

        $this->actingAs($user)
            ->get('/competitors/'.$account->id)
            ->assertRedirect('/snitches/'.$account->id);
    }

    public function test_owner_can_list_and_create_competitors(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        BrandProfile::factory()->for($user)->create();
        $this->enablePlatformBilling($user);

        $this->actingAs($user)
            ->get(route('competitors.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('competitors/Index')
                ->missing('accounts')
                ->missing('suggestions')
                ->where('suggestRun', null)
                ->where('platforms', ['tiktok', 'instagram', 'facebook', 'linkedin', 'youtube'])
                ->loadDeferredProps('suggestions', fn (Assert $page) => $page->has('suggestions', 0))
            );

        $this->actingAs($user)
            ->post(route('competitors.store'), [
                'platform' => 'instagram',
                'handle' => '@rivalbakery',
            ])
            ->assertRedirect(route('competitors.index'));

        $this->assertDatabaseHas('tracked_accounts', [
            'user_id' => $user->id,
            'handle' => 'rivalbakery',
            'platform' => 'instagram',
            'url' => 'https://instagram.com/rivalbakery',
        ]);

        Queue::assertPushed(SyncTrackedAccountJob::class);
    }

    public function test_confirm_suggestions_creates_tracked_accounts(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        BrandProfile::factory()->for($user)->create();
        $this->enablePlatformBilling($user);

        $this->actingAs($user)
            ->post(route('competitors.confirm-suggestions'), [
                'suggestions' => [
                    [
                        'platform' => 'instagram',
                        'handle' => 'rivalbakery',
                        'display_name' => 'Rival Bakery',
                    ],
                ],
            ])
            ->assertRedirect(route('competitors.index'));

        $this->assertDatabaseHas('tracked_accounts', [
            'user_id' => $user->id,
            'handle' => 'rivalbakery',
            'platform' => 'instagram',
            'url' => 'https://instagram.com/rivalbakery',
            'display_name' => 'Rival Bakery',
        ]);
        Queue::assertPushed(SyncTrackedAccountJob::class);
    }

    public function test_owner_can_delete_and_sync(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        BrandProfile::factory()->for($user)->create();
        $this->enablePlatformBilling($user);
        $account = TrackedAccount::factory()->for($user)->create();

        $this->actingAs($user)
            ->post(route('competitors.sync', $account))
            ->assertRedirect();

        Queue::assertPushed(SyncTrackedAccountJob::class);

        $this->actingAs($user)
            ->delete(route('competitors.destroy', $account))
            ->assertRedirect(route('competitors.index'));

        $this->assertDatabaseMissing('tracked_accounts', ['id' => $account->id]);
    }

    public function test_manual_sync_force_queues_when_synced_within_min_interval(): void
    {
        Queue::fake();

        config(['snitch.sync.min_interval_days' => 7]);

        $user = User::factory()->create();
        BrandProfile::factory()->for($user)->create();
        $this->enablePlatformBilling($user);
        $account = TrackedAccount::factory()->for($user)->create([
            'last_synced_at' => now()->subDays(2),
            'last_sync_status' => 'success',
        ]);

        $this->actingAs($user)
            ->post(route('competitors.sync', $account))
            ->assertRedirect();

        Queue::assertPushed(SyncTrackedAccountJob::class, function (SyncTrackedAccountJob $job) use ($account): bool {
            return $job->trackedAccountId === $account->id && $job->force === true;
        });

        $this->assertSame('running', $account->fresh()?->last_sync_status);
    }

    public function test_create_competitor_skips_sync_when_balance_too_low(): void
    {
        Queue::fake();

        $user = User::factory()->withoutStarterCredit()->create();
        BrandProfile::factory()->for($user)->create();

        $this->actingAs($user)
            ->post(route('competitors.store'), [
                'platform' => 'instagram',
                'handle' => '@rivalbakery',
            ])
            ->assertRedirect(route('billing.edit'));

        $this->assertDatabaseMissing('tracked_accounts', [
            'user_id' => $user->id,
            'handle' => 'rivalbakery',
        ]);
        Queue::assertNotPushed(SyncTrackedAccountJob::class);
    }

    public function test_manual_sync_is_blocked_when_balance_too_low(): void
    {
        Queue::fake();

        $user = User::factory()->withoutStarterCredit()->create();
        BrandProfile::factory()->for($user)->create();
        $this->createPlatformSubscription($user);
        app(UsageBillingService::class)->creditFromTopUp($user, 20, 'topup:twenty');
        $account = TrackedAccount::factory()->for($user)->create([
            'last_synced_at' => now()->subDays(2),
            'last_sync_status' => 'success',
        ]);

        $this->actingAs($user)
            ->post(route('competitors.sync', $account))
            ->assertRedirect();

        Queue::assertNotPushed(SyncTrackedAccountJob::class);
        $this->assertSame('success', $account->fresh()?->last_sync_status);
    }

    public function test_index_exposes_sync_status_and_controls(): void
    {
        config(['snitch.sync.min_interval_days' => 7]);

        $user = User::factory()->create();
        BrandProfile::factory()->for($user)->create();
        $syncedAt = now()->subDays(3)->startOfSecond();
        TrackedAccount::factory()->for($user)->create([
            'handle' => 'rivalbakery',
            'last_synced_at' => $syncedAt,
            'last_sync_status' => 'success',
        ]);

        $this->actingAs($user)
            ->get(route('competitors.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('competitors/Index')
                ->missing('accounts')
                ->loadDeferredProps('default', fn (Assert $page) => $page
                    ->has('accounts', 1)
                    ->where('accounts.0.handle', 'rivalbakery')
                    ->where('accounts.0.last_sync_status', 'success')
                    ->where('accounts.0.last_synced_at', $syncedAt->toJSON())
                    ->missing('accounts.0.next_sync_at')
                    ->missing('accounts.0.sync_due')
                )
            );

        $indexVue = file_get_contents(resource_path('js/pages/competitors/Index.vue'));
        $this->assertIsString($indexVue);
        $this->assertStringContainsString('Sync status', $indexVue);
        $this->assertStringContainsString('accountSyncStatusLabel', $indexVue);
        $this->assertStringContainsString('syncAccount', $indexVue);
        $this->assertStringContainsString('isAccountSyncing', $indexVue);
        $this->assertStringContainsString('Sync in progress', $indexVue);
        $this->assertStringContainsString('emptyImportHint', $indexVue);
        $this->assertStringContainsString('No recent reels found', $indexVue);
        $this->assertStringContainsString('RemoveCompetitorModal', $indexVue);
        $this->assertStringContainsString('askRemove', $indexVue);
        $this->assertStringNotContainsString('Auto sync', $indexVue);
        $this->assertStringNotContainsString('nextSyncLabel', $indexVue);
        $this->assertStringNotContainsString('Sync ok', $indexVue);
        $this->assertStringNotContainsString('not synced', $indexVue);
    }

    public function test_index_exposes_running_sync_status(): void
    {
        $user = User::factory()->create();
        BrandProfile::factory()->for($user)->create();
        TrackedAccount::factory()->for($user)->create([
            'handle' => 'rivalbakery',
            'last_sync_status' => 'running',
        ]);

        $this->actingAs($user)
            ->get(route('competitors.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('competitors/Index')
                ->missing('accounts')
                ->loadDeferredProps('default', fn (Assert $page) => $page
                    ->where('accounts.0.last_sync_status', 'running')
                )
            );
    }

    public function test_index_exposes_reel_backlog_and_winner_counts(): void
    {
        $user = User::factory()->create();
        BrandProfile::factory()->for($user)->create();
        $account = TrackedAccount::factory()->for($user)->create([
            'handle' => 'rivalbakery',
        ]);

        $readyReel = Post::factory()->forAccount($account)->create([
            'type' => PostType::Reel,
        ]);
        PostAnalysis::factory()->for($readyReel)->create();
        WinnerInsight::factory()->forPost($readyReel)->create([
            'user_id' => $user->id,
            'score' => 82,
        ]);

        $backlogReel = Post::factory()->forAccount($account)->create([
            'type' => PostType::Video,
        ]);
        PostAnalysis::factory()->pending()->for($backlogReel)->create();

        Post::factory()->forAccount($account)->create([
            'type' => PostType::Image,
        ]);

        $this->actingAs($user)
            ->get(route('competitors.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('competitors/Index')
                ->missing('accounts')
                ->loadDeferredProps('default', fn (Assert $page) => $page
                    ->where('accounts.0.reels_count', 2)
                    ->where('accounts.0.analysis_backlog_count', 1)
                    ->where('accounts.0.winners_count', 1)
                    ->missing('accounts.0.posts_count')
                )
            );
    }

    public function test_remove_competitor_modal_confirms_before_destroy(): void
    {
        $modalVue = file_get_contents(resource_path('js/components/RemoveCompetitorModal.vue'));
        $this->assertIsString($modalVue);
        $this->assertStringContainsString('Remove this snitch?', $modalVue);
        $this->assertStringContainsString('confirm-remove-competitor-button', $modalVue);
        $this->assertStringContainsString('Cancel', $modalVue);
        $this->assertStringContainsString('router.delete', $modalVue);
    }

    public function test_other_user_cannot_delete_competitor(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        BrandProfile::factory()->for($other)->create();
        $account = TrackedAccount::factory()->for($owner)->create();

        $this->actingAs($other)
            ->delete(route('competitors.destroy', $account))
            ->assertForbidden();
    }
}
