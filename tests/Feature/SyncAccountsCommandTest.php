<?php

namespace Tests\Feature;

use App\Jobs\SyncTrackedAccountJob;
use App\Models\TrackedAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\WithPlatformBilling;
use Tests\TestCase;

class SyncAccountsCommandTest extends TestCase
{
    use RefreshDatabase;
    use WithPlatformBilling;

    public function test_enqueues_only_accounts_due_for_weekly_sync(): void
    {
        Queue::fake();

        config(['snitch.sync.min_interval_days' => 7]);

        // Trial grants Basic competitor limits so four accounts stay in-quota and
        // this test can assert weekly due/skip behavior rather than over-quota skips.
        $user = User::factory()->onTrial()->create();
        $this->enablePlatformBilling($user);

        $dueNeverSynced = TrackedAccount::factory()->for($user)->create([
            'last_synced_at' => null,
            'last_sync_status' => null,
        ]);

        $dueStale = TrackedAccount::factory()->for($user)->create([
            'last_synced_at' => now()->subDays(8),
            'last_sync_status' => 'success',
        ]);

        $dueFailed = TrackedAccount::factory()->for($user)->create([
            'last_synced_at' => now()->subDay(),
            'last_sync_status' => 'failed',
        ]);

        $skippedRecent = TrackedAccount::factory()->for($user)->create([
            'last_synced_at' => now()->subDays(2),
            'last_sync_status' => 'success',
        ]);

        $this->artisan('snitch:sync-accounts')
            ->expectsOutputToContain('Enqueued 3 account sync jobs (1 skipped recently; 0 over quota; 0 low balance).')
            ->assertSuccessful();

        Queue::assertPushed(SyncTrackedAccountJob::class, 3);
        Queue::assertPushed(
            SyncTrackedAccountJob::class,
            fn (SyncTrackedAccountJob $job) => $job->trackedAccountId === $dueNeverSynced->id,
        );
        Queue::assertPushed(
            SyncTrackedAccountJob::class,
            fn (SyncTrackedAccountJob $job) => $job->trackedAccountId === $dueStale->id,
        );
        Queue::assertPushed(
            SyncTrackedAccountJob::class,
            fn (SyncTrackedAccountJob $job) => $job->trackedAccountId === $dueFailed->id,
        );
        Queue::assertNotPushed(
            SyncTrackedAccountJob::class,
            fn (SyncTrackedAccountJob $job) => $job->trackedAccountId === $skippedRecent->id,
        );

        $this->assertSame('running', $dueNeverSynced->fresh()?->last_sync_status);
        $this->assertSame('running', $dueStale->fresh()?->last_sync_status);
        $this->assertSame('running', $dueFailed->fresh()?->last_sync_status);
        $this->assertSame('success', $skippedRecent->fresh()?->last_sync_status);
    }
}
