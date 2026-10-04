<?php

namespace Tests\Feature\DailyBrief;

use App\Enums\Platform;
use App\Enums\TrackedAccountKind;
use App\Jobs\RefreshFollowerCountJob;
use App\Jobs\SyncTrackedAccountJob;
use App\Models\TrackedAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class DailyRefreshCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_refresh_only_queues_enabled_users(): void
    {
        Queue::fake();

        $enabled = User::factory()->create(['daily_brief_enabled' => true]);
        $disabled = User::factory()->create(['daily_brief_enabled' => false]);

        TrackedAccount::factory()->for($enabled)->create([
            'platform' => Platform::Instagram,
            'handle' => 'letsgosocialuk',
            'is_own_account' => true,
            'kind' => TrackedAccountKind::Competitor,
        ]);
        TrackedAccount::factory()->for($enabled)->create([
            'platform' => Platform::Instagram,
            'handle' => 'onehousesocialclub',
            'is_own_account' => false,
            'kind' => TrackedAccountKind::Competitor,
        ]);
        TrackedAccount::factory()->for($disabled)->create([
            'platform' => Platform::Instagram,
            'handle' => 'ignored',
            'is_own_account' => true,
            'kind' => TrackedAccountKind::Competitor,
        ]);

        $this->artisan('snitch:daily-refresh')->assertSuccessful();

        Queue::assertPushed(SyncTrackedAccountJob::class, 2);
        Queue::assertPushed(SyncTrackedAccountJob::class, function (SyncTrackedAccountJob $job) use ($enabled): bool {
            $account = TrackedAccount::query()->find($job->trackedAccountId);

            return $account !== null
                && (int) $account->user_id === (int) $enabled->id
                && $job->force === true
                && $job->postsLimit === 6
                && $job->resolveProfile === false;
        });
        Queue::assertPushed(RefreshFollowerCountJob::class);
        Queue::assertPushed(RefreshFollowerCountJob::class, function (RefreshFollowerCountJob $job): bool {
            return $job->force === true;
        });
    }

    public function test_refresh_skips_users_who_fail_can_run(): void
    {
        Queue::fake();

        $user = User::factory()->withoutPlatformSubscription()->create([
            'daily_brief_enabled' => true,
        ]);
        TrackedAccount::factory()->for($user)->create([
            'platform' => Platform::Instagram,
            'handle' => 'blocked',
            'is_own_account' => true,
            'kind' => TrackedAccountKind::Competitor,
        ]);

        $this->artisan('snitch:daily-refresh')->assertSuccessful();

        Queue::assertNotPushed(SyncTrackedAccountJob::class);
        Queue::assertNotPushed(RefreshFollowerCountJob::class);
    }

    public function test_refresh_user_option_limits_scope(): void
    {
        Queue::fake();

        $one = User::factory()->create(['daily_brief_enabled' => true]);
        $two = User::factory()->create(['daily_brief_enabled' => true]);
        TrackedAccount::factory()->for($one)->create([
            'platform' => Platform::Instagram,
            'is_own_account' => true,
            'kind' => TrackedAccountKind::Competitor,
        ]);
        TrackedAccount::factory()->for($two)->create([
            'platform' => Platform::Instagram,
            'is_own_account' => true,
            'kind' => TrackedAccountKind::Competitor,
        ]);

        $this->artisan('snitch:daily-refresh', ['--user' => $one->id])->assertSuccessful();

        Queue::assertPushed(SyncTrackedAccountJob::class, 1);
        Queue::assertPushed(SyncTrackedAccountJob::class, function (SyncTrackedAccountJob $job) use ($one): bool {
            $account = TrackedAccount::query()->find($job->trackedAccountId);

            return $account !== null && (int) $account->user_id === (int) $one->id;
        });
    }
}
