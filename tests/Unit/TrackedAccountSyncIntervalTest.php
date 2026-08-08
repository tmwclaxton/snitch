<?php

namespace Tests\Unit;

use App\Models\TrackedAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TrackedAccountSyncIntervalTest extends TestCase
{
    use RefreshDatabase;

    public function test_never_synced_and_failed_are_due(): void
    {
        config(['snitch.sync.min_interval_days' => 7]);

        $never = TrackedAccount::factory()->make([
            'last_synced_at' => null,
            'last_sync_status' => null,
        ]);
        $failed = TrackedAccount::factory()->make([
            'last_synced_at' => now()->subDay(),
            'last_sync_status' => 'failed',
        ]);

        $this->assertTrue($never->isDueForSync());
        $this->assertTrue($failed->isDueForSync());
    }

    public function test_recent_success_is_not_due_until_interval_elapses(): void
    {
        config(['snitch.sync.min_interval_days' => 7]);

        $recent = TrackedAccount::factory()->make([
            'last_synced_at' => now()->subDays(3),
            'last_sync_status' => 'success',
        ]);
        $stale = TrackedAccount::factory()->make([
            'last_synced_at' => now()->subDays(7),
            'last_sync_status' => 'success',
        ]);

        $this->assertFalse($recent->isDueForSync());
        $this->assertTrue($stale->isDueForSync());

        $this->assertNull($stale->nextSyncAt());
        $this->assertNotNull($recent->nextSyncAt());
        $this->assertTrue(
            $recent->nextSyncAt()?->equalTo($recent->last_synced_at->copy()->addDays(7)) ?? false,
        );
    }

    public function test_running_sync_is_not_due(): void
    {
        config(['snitch.sync.min_interval_days' => 7]);

        $running = TrackedAccount::factory()->make([
            'last_synced_at' => null,
            'last_sync_status' => 'running',
        ]);

        $this->assertTrue($running->isSyncing());
        $this->assertFalse($running->isDueForSync());
    }
}
