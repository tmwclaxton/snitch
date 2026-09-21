<?php

namespace Tests\Unit\Services\Tracking;

use App\Models\BrandProfile;
use App\Models\TrackedAccount;
use App\Models\User;
use App\Services\Tracking\WatchingYouService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WatchingYouServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_lookup_counts_other_users_tracking_the_handle(): void
    {
        $viewer = User::factory()->create();
        $watcher = User::factory()->create();
        $second = User::factory()->create();

        TrackedAccount::factory()->for($viewer)->create(['handle' => 'loaflocal']);
        TrackedAccount::factory()->for($watcher)->create(['handle' => 'LoafLocal']);
        TrackedAccount::factory()->for($second)->create(['handle' => '@loaflocal']);
        TrackedAccount::factory()->for($second)->influencer()->create(['handle' => 'loaflocal']);

        $result = app(WatchingYouService::class)->lookup('loaflocal', $viewer->id);

        $this->assertSame('loaflocal', $result['handle']);
        $this->assertTrue($result['watched']);
        $this->assertSame(2, $result['watcher_count']);
    }

    public function test_brand_handles_exclude_the_current_user(): void
    {
        $user = User::factory()->create();
        BrandProfile::factory()->for($user)->create([
            'own_handles' => ['instagram' => '@onlymine'],
        ]);
        TrackedAccount::factory()->for($user)->create(['handle' => 'onlymine']);

        $results = app(WatchingYouService::class)->forBrandHandles($user);

        $this->assertCount(1, $results);
        $this->assertFalse($results[0]['watched']);
        $this->assertSame(0, $results[0]['watcher_count']);
        $this->assertSame('instagram', $results[0]['platform']);
    }
}
