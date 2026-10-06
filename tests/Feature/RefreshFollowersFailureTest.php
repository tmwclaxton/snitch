<?php

namespace Tests\Feature;

use App\Enums\Platform;
use App\Jobs\AnalyzePostJob;
use App\Jobs\RefreshFollowerCountJob;
use App\Jobs\ScoreWinnersJob;
use App\Jobs\SyncTrackedAccountJob;
use App\Models\FollowerSnapshot;
use App\Models\SocialAccount;
use App\Models\TrackedAccount;
use App\Models\User;
use App\Services\Apify\ApifyClient;
use App\Services\Apify\Contracts\PlatformAdapter;
use App\Services\Apify\PlatformAdapterManager;
use App\Services\Billing\VendorUsageCharger;
use App\Services\SnitchAnalyticsService;
use App\Services\Tracking\FollowerCountRefresher;
use App\Services\Tracking\FollowerSnapshotRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use RuntimeException;
use Tests\Concerns\WithPlatformBilling;
use Tests\TestCase;

class RefreshFollowersFailureTest extends TestCase
{
    use RefreshDatabase;
    use WithPlatformBilling;

    public function test_record_from_account_does_not_write_a_snapshot(): void
    {
        $account = TrackedAccount::factory()->create([
            'platform' => Platform::Instagram,
            'followers' => 97,
        ]);

        app(FollowerSnapshotRecorder::class)->recordFromAccount($account);

        $this->assertSame(0, FollowerSnapshot::query()->count());
    }

    public function test_copied_today_snapshot_stays_due_and_force_overwrites(): void
    {
        $social = SocialAccount::factory()->forPlatform(Platform::Instagram)->create([
            'handle' => 'letsgosocialuk',
        ]);
        TrackedAccount::factory()->forSocialAccount($social)->create([
            'followers' => 97,
        ]);
        FollowerSnapshot::factory()->create([
            'social_account_id' => $social->id,
            'followers' => 97,
            'source' => null,
            'captured_on' => now()->toDateString(),
        ]);

        $this->assertSame([(int) $social->id], app(FollowerCountRefresher::class)->dueSocialAccountIds());

        $adapter = Mockery::mock(PlatformAdapter::class);
        $adapter->shouldReceive('resolveProfile')->once()->andReturn([
            'platform' => Platform::Instagram,
            'handle' => 'letsgosocialuk',
            'url' => 'https://instagram.com/letsgosocialuk',
            'external_id' => '1',
            'avatar' => null,
            'display_name' => 'Let\'s Go Social',
            'followers' => 101,
        ]);
        $manager = Mockery::mock(PlatformAdapterManager::class);
        $manager->shouldReceive('for')->andReturn($adapter);
        $manager->shouldReceive('driverFor')->andReturn('apify');
        $this->app->instance(PlatformAdapterManager::class, $manager);

        $this->assertTrue(app(FollowerCountRefresher::class)->refresh($social->fresh(), true));
        $this->assertSame(1, FollowerSnapshot::query()->where('social_account_id', $social->id)->count());
        $this->assertSame(101, FollowerSnapshot::query()->where('social_account_id', $social->id)->value('followers'));
        $this->assertSame('profile', FollowerSnapshot::query()->where('social_account_id', $social->id)->value('source'));
    }

    public function test_force_refetches_even_when_today_is_already_a_profile_snapshot(): void
    {
        $social = SocialAccount::factory()->forPlatform(Platform::Instagram)->create([
            'handle' => 'goodgym',
        ]);
        TrackedAccount::factory()->forSocialAccount($social)->create([
            'followers' => 28768,
        ]);
        FollowerSnapshot::factory()->create([
            'social_account_id' => $social->id,
            'followers' => 28768,
            'source' => 'profile',
            'captured_on' => now()->toDateString(),
        ]);

        $this->assertSame([], app(FollowerCountRefresher::class)->dueSocialAccountIds());
        $this->assertFalse(app(FollowerCountRefresher::class)->refresh($social->fresh()));

        $adapter = Mockery::mock(PlatformAdapter::class);
        $adapter->shouldReceive('resolveProfile')->once()->andReturn([
            'platform' => Platform::Instagram,
            'handle' => 'goodgym',
            'url' => 'https://instagram.com/goodgym',
            'external_id' => '2',
            'avatar' => null,
            'display_name' => 'GoodGym',
            'followers' => 28800,
        ]);
        $manager = Mockery::mock(PlatformAdapterManager::class);
        $manager->shouldReceive('for')->andReturn($adapter);
        $manager->shouldReceive('driverFor')->andReturn('apify');
        $this->app->instance(PlatformAdapterManager::class, $manager);

        $this->assertTrue(app(FollowerCountRefresher::class)->refresh($social->fresh(), true));
        $this->assertSame(28800, FollowerSnapshot::query()->where('social_account_id', $social->id)->value('followers'));
        $this->assertSame('profile', FollowerSnapshot::query()->where('social_account_id', $social->id)->value('source'));
    }

    public function test_refresh_falls_back_to_apify_when_tikhub_fails(): void
    {
        $social = SocialAccount::factory()->forPlatform(Platform::Instagram)->create([
            'handle' => 'sobersocial_',
        ]);
        TrackedAccount::factory()->forSocialAccount($social)->create([
            'followers' => 19936,
        ]);

        $tikhub = Mockery::mock(PlatformAdapter::class);
        $tikhub->shouldReceive('resolveProfile')->once()->andThrow(new RuntimeException('TikHub request failed (400)'));
        $apify = Mockery::mock(PlatformAdapter::class);
        $apify->shouldReceive('resolveProfile')->once()->andReturn([
            'platform' => Platform::Instagram,
            'handle' => 'sobersocial_',
            'url' => 'https://instagram.com/sobersocial_',
            'external_id' => '3',
            'avatar' => null,
            'display_name' => 'Sober Social',
            'followers' => 19940,
        ]);
        $manager = Mockery::mock(PlatformAdapterManager::class);
        $manager->shouldReceive('for')->andReturn($tikhub);
        $manager->shouldReceive('driverFor')->andReturn('tikhub');
        $manager->shouldReceive('apifyAdapter')->andReturn($apify);
        $this->app->instance(PlatformAdapterManager::class, $manager);

        $this->assertTrue(app(FollowerCountRefresher::class)->refresh($social->fresh()));
        $this->assertSame(19940, FollowerSnapshot::query()->where('social_account_id', $social->id)->value('followers'));
    }

    public function test_command_fails_when_every_fetch_fails_and_queues_a_retry(): void
    {
        Queue::fake();

        $account = TrackedAccount::factory()->create([
            'platform' => Platform::Instagram,
            'handle' => 'letsgosocialuk',
            'followers' => 97,
        ]);

        $adapter = Mockery::mock(PlatformAdapter::class);
        $adapter->shouldReceive('resolveProfile')->andThrow(new RuntimeException('TikHub request failed (400)'));
        $manager = Mockery::mock(PlatformAdapterManager::class);
        $manager->shouldReceive('for')->andReturn($adapter);
        $manager->shouldReceive('driverFor')->andReturn('apify');
        $this->app->instance(PlatformAdapterManager::class, $manager);

        $this->artisan('snitch:refresh-followers')->assertFailed();

        Queue::assertPushed(
            RefreshFollowerCountJob::class,
            fn (RefreshFollowerCountJob $job): bool => $job->socialAccountId === (int) $account->social_account_id,
        );
        $this->assertSame(0, FollowerSnapshot::query()->count());
    }

    public function test_sync_without_a_fresh_profile_does_not_write_a_snapshot(): void
    {
        Queue::fake([AnalyzePostJob::class, ScoreWinnersJob::class]);

        $user = User::factory()->create();
        $this->enablePlatformBilling($user);
        $account = TrackedAccount::factory()->for($user)->create([
            'platform' => Platform::Instagram,
            'handle' => 'letsgosocialuk',
            'external_id' => '1',
            'url' => 'https://instagram.com/letsgosocialuk',
            'display_name' => 'Let\'s Go Social',
            'followers' => 97,
            'last_synced_at' => now()->subDays(8),
        ]);

        $adapter = Mockery::mock(PlatformAdapter::class);
        $adapter->shouldReceive('platform')->andReturn(Platform::Instagram);
        $adapter->shouldReceive('resolveProfile')->never();
        $adapter->shouldReceive('listRecentPosts')->once()->andReturn([]);
        $adapter->shouldReceive('hydrateMediaUrls')->once()->andReturn([]);

        $adapters = Mockery::mock(PlatformAdapterManager::class);
        $adapters->shouldReceive('driverFor')->andReturn('apify');
        $adapters->shouldReceive('for')->andReturn($adapter);
        $adapters->shouldReceive('tikHubAdapter')->andReturn(null);

        $client = Mockery::mock(ApifyClient::class);
        $client->shouldReceive('pullRunCosts')->andReturn([]);
        $this->app->instance(ApifyClient::class, $client);

        (new SyncTrackedAccountJob($account->id, force: true, postsLimit: 6, recencyDays: 30, resolveProfile: false))->handle(
            $adapters,
            app(SnitchAnalyticsService::class),
            app(VendorUsageCharger::class),
        );

        $this->assertSame(0, FollowerSnapshot::query()->count());
        $this->assertSame(97, $account->fresh()?->followers);
    }

    public function test_sync_records_a_snapshot_only_after_a_fresh_profile_fetch(): void
    {
        Queue::fake([AnalyzePostJob::class, ScoreWinnersJob::class]);

        $user = User::factory()->create();
        $this->enablePlatformBilling($user);
        $account = TrackedAccount::factory()->for($user)->create([
            'platform' => Platform::Instagram,
            'handle' => 'letsgosocialuk',
            'external_id' => '1',
            'url' => 'https://instagram.com/letsgosocialuk',
            'display_name' => 'Let\'s Go Social',
            'followers' => 97,
            'last_synced_at' => now()->subDays(8),
        ]);

        $adapter = Mockery::mock(PlatformAdapter::class);
        $adapter->shouldReceive('platform')->andReturn(Platform::Instagram);
        $adapter->shouldReceive('resolveProfile')->once()->andReturn([
            'handle' => 'letsgosocialuk',
            'url' => 'https://instagram.com/letsgosocialuk',
            'external_id' => '1',
            'display_name' => 'Let\'s Go Social',
            'followers' => 101,
        ]);
        $adapter->shouldReceive('listRecentPosts')->once()->andReturn([]);
        $adapter->shouldReceive('hydrateMediaUrls')->once()->andReturn([]);

        $adapters = Mockery::mock(PlatformAdapterManager::class);
        $adapters->shouldReceive('driverFor')->andReturn('apify');
        $adapters->shouldReceive('for')->andReturn($adapter);
        $adapters->shouldReceive('tikHubAdapter')->andReturn(null);

        $client = Mockery::mock(ApifyClient::class);
        $client->shouldReceive('pullRunCosts')->andReturn([]);
        $this->app->instance(ApifyClient::class, $client);

        (new SyncTrackedAccountJob($account->id, force: true, postsLimit: 6, recencyDays: 30, resolveProfile: true))->handle(
            $adapters,
            app(SnitchAnalyticsService::class),
            app(VendorUsageCharger::class),
        );

        $this->assertSame(1, FollowerSnapshot::query()->count());
        $this->assertSame(101, FollowerSnapshot::query()->value('followers'));
        $this->assertSame('profile', FollowerSnapshot::query()->value('source'));
    }
}
