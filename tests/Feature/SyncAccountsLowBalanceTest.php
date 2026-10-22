<?php

namespace Tests\Feature;

use App\Enums\Platform;
use App\Enums\TrackedAccountKind;
use App\Jobs\SyncTrackedAccountJob;
use App\Models\Post;
use App\Models\TrackedAccount;
use App\Models\User;
use App\Services\Billing\UsageBillingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\WithPlatformBilling;
use Tests\TestCase;

class SyncAccountsLowBalanceTest extends TestCase
{
    use RefreshDatabase;
    use WithPlatformBilling;

    public function test_low_balance_records_skipped_balance_without_enqueueing(): void
    {
        Queue::fake();

        $user = User::factory()->withoutStarterCredit()->create();
        $this->assertFalse(app(UsageBillingService::class)->canRun($user));

        $tracker = TrackedAccount::factory()->for($user)->create([
            'platform' => Platform::Instagram,
            'handle' => 'samsung',
            'followers' => 9000,
            'last_synced_at' => null,
            'last_sync_status' => null,
            'kind' => TrackedAccountKind::Competitor,
        ]);

        $this->artisan('snitch:sync-accounts')
            ->expectsOutputToContain('low balance')
            ->assertSuccessful();

        Queue::assertNothingPushed();

        $tracker->refresh();
        $this->assertNull($tracker->last_synced_at);
        $this->assertSame('skipped_balance', $tracker->last_sync_status);
        $this->assertStringContainsString('credit balance', (string) $tracker->last_sync_error);
        $this->assertTrue($tracker->isDueForSync());
    }

    public function test_low_balance_inherits_corpus_sync_state_from_sibling(): void
    {
        Queue::fake();

        $rich = User::factory()->onTrial()->create();
        $this->enablePlatformBilling($rich);

        $poor = User::factory()->withoutStarterCredit()->create();
        $this->assertFalse(app(UsageBillingService::class)->canRun($poor));

        $donor = TrackedAccount::factory()->for($rich)->create([
            'platform' => Platform::Instagram,
            'handle' => 'sharedbrand',
            'last_synced_at' => now()->subHour(),
            'last_sync_status' => 'success',
        ]);
        Post::factory()->forAccount($donor)->create([
            'posted_at' => now()->subDay(),
        ]);

        $heir = TrackedAccount::factory()->for($poor)->forSocialAccount($donor->socialAccount)->create([
            'platform' => Platform::Instagram,
            'handle' => 'sharedbrand',
            'last_synced_at' => null,
            'last_sync_status' => null,
        ]);

        $this->artisan('snitch:sync-accounts')->assertSuccessful();

        Queue::assertNotPushed(
            SyncTrackedAccountJob::class,
            fn (SyncTrackedAccountJob $job) => $job->trackedAccountId === $heir->id,
        );

        $heir->refresh();
        $this->assertNotNull($heir->last_synced_at);
        $this->assertSame('success', $heir->last_sync_status);
        $this->assertTrue(
            Post::query()->where('social_account_id', $heir->social_account_id)->exists(),
        );
    }
}
