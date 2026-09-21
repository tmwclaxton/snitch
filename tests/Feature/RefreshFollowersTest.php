<?php

namespace Tests\Feature;

use App\Enums\Platform;
use App\Jobs\RefreshFollowerCountJob;
use App\Models\FollowerSnapshot;
use App\Models\SocialAccount;
use App\Models\TrackedAccount;
use App\Models\User;
use App\Services\Apify\Contracts\PlatformAdapter;
use App\Services\Apify\PlatformAdapterManager;
use App\Services\Competitors\CompetitorInsightsBuilder;
use App\Services\Tracking\FollowerCountRefresher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class RefreshFollowersTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_queues_only_accounts_someone_still_tracks(): void
    {
        Queue::fake();

        $tracked = TrackedAccount::factory()->create([
            'platform' => Platform::Instagram,
            'handle' => 'stilltracked',
            'followers' => 1000,
        ]);
        $recent = TrackedAccount::factory()->create([
            'platform' => Platform::Instagram,
            'handle' => 'refreshed',
            'followers' => 2000,
        ]);
        FollowerSnapshot::factory()->create([
            'social_account_id' => $recent->social_account_id,
            'followers' => 2000,
            'captured_on' => now()->toDateString(),
        ]);
        $dropped = SocialAccount::factory()->forPlatform(Platform::Instagram)->create([
            'handle' => 'untracked',
        ]);

        $this->artisan('snitch:refresh-followers')
            ->expectsOutputToContain('Enqueued 1 follower refreshes.')
            ->assertSuccessful();

        Queue::assertPushed(RefreshFollowerCountJob::class, 1);
        Queue::assertPushed(
            RefreshFollowerCountJob::class,
            fn (RefreshFollowerCountJob $job): bool => $job->socialAccountId === $tracked->social_account_id,
        );
        Queue::assertNotPushed(
            RefreshFollowerCountJob::class,
            fn (RefreshFollowerCountJob $job): bool => $job->socialAccountId === $dropped->id
                || $job->socialAccountId === $recent->social_account_id,
        );
    }

    public function test_refresh_records_one_snapshot_and_stops_after_untrack(): void
    {
        $social = SocialAccount::factory()->forPlatform(Platform::Instagram)->create([
            'handle' => 'socialchain',
        ]);
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $first = TrackedAccount::factory()->for($owner)->forSocialAccount($social)->create([
            'followers' => 1000,
            'last_synced_at' => now()->subDays(3),
        ]);
        $second = TrackedAccount::factory()->for($other)->forSocialAccount($social)->create([
            'followers' => 1000,
        ]);

        $adapter = Mockery::mock(PlatformAdapter::class);
        $adapter->shouldReceive('resolveProfile')->once()->andReturn([
            'platform' => Platform::Instagram,
            'handle' => 'socialchain',
            'url' => 'https://instagram.com/socialchain',
            'external_id' => '1',
            'avatar' => null,
            'display_name' => 'SocialChain',
            'followers' => 55760,
        ]);
        $manager = Mockery::mock(PlatformAdapterManager::class);
        $manager->shouldReceive('for')->once()->with(Mockery::on(
            fn (Platform $platform): bool => $platform === Platform::Instagram,
        ))->andReturn($adapter);
        $this->app->instance(PlatformAdapterManager::class, $manager);

        $this->assertTrue(app(FollowerCountRefresher::class)->refresh($social));
        $this->assertFalse(app(FollowerCountRefresher::class)->refresh($social->fresh()));

        $this->assertSame(55760, $first->fresh()?->followers);
        $this->assertSame(55760, $second->fresh()?->followers);
        $this->assertNotNull($first->fresh()?->last_synced_at);
        $this->assertSame(1, FollowerSnapshot::query()->where('social_account_id', $social->id)->count());

        $first->delete();
        $second->delete();

        $this->assertSame([], app(FollowerCountRefresher::class)->dueSocialAccountIds());
    }

    public function test_account_page_series_does_not_invent_a_week_of_growth(): void
    {
        $user = User::factory()->create();
        $account = TrackedAccount::factory()->for($user)->create([
            'followers' => 55760,
        ]);
        FollowerSnapshot::factory()->create([
            'social_account_id' => $account->social_account_id,
            'followers' => 55760,
            'captured_on' => now()->toDateString(),
        ]);

        $insights = app(CompetitorInsightsBuilder::class)->forAccount($user, $account);

        $this->assertNull($insights['growth']['week_delta']);
        $this->assertCount(1, $insights['follower_series']);
        $this->assertSame(55760, $insights['follower_series'][0]['followers']);
    }
}
