<?php

namespace Tests\Unit\Services\Tracking;

use App\Enums\Platform;
use App\Models\BrandProfile;
use App\Models\TrackedAccount;
use App\Models\User;
use App\Services\Tracking\TrackedByService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TrackedByServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_null_when_nobody_is_watching(): void
    {
        $user = User::factory()->create();
        BrandProfile::factory()->for($user)->create([
            'own_handles' => ['instagram' => '@alonebrand'],
        ]);

        $this->assertNull(app(TrackedByService::class)->forUser($user));
    }

    public function test_counts_distinct_watchers_by_platform_and_handle(): void
    {
        $user = User::factory()->create();
        BrandProfile::factory()->for($user)->create([
            'own_handles' => ['instagram' => '@LoafLocal'],
        ]);

        $first = User::factory()->create();
        $second = User::factory()->create();
        User::factory()->create(); // unrelated

        TrackedAccount::factory()->for($first)->forPlatform(Platform::Instagram)->create([
            'handle' => 'loaflocal',
            'created_at' => now()->subDays(10),
        ]);
        TrackedAccount::factory()->for($second)->forPlatform(Platform::Instagram)->create([
            'handle' => '@LoafLocal',
            'created_at' => now()->subDays(3),
        ]);
        // Same watcher, second platform should not double-count the user.
        TrackedAccount::factory()->for($first)->forPlatform(Platform::TikTok)->create([
            'handle' => 'loaflocal',
        ]);
        // Soft-deleted tracker ignored.
        TrackedAccount::factory()->for(User::factory()->create())->forPlatform(Platform::Instagram)->create([
            'handle' => 'loaflocal',
            'deleted_at' => now(),
        ]);
        // Self-tracking ignored.
        TrackedAccount::factory()->for($user)->forPlatform(Platform::Instagram)->create([
            'handle' => 'loaflocal',
            'is_own_account' => true,
        ]);

        $result = app(TrackedByService::class)->forUser($user->fresh(['brandProfile']));

        $this->assertNotNull($result);
        $this->assertSame(2, $result['count']);
        $this->assertNotSame('', $result['since']);
    }

    public function test_uses_own_tracked_account_when_brand_handle_missing(): void
    {
        $user = User::factory()->create();
        BrandProfile::factory()->for($user)->create(['own_handles' => []]);
        TrackedAccount::factory()->for($user)->forPlatform(Platform::Instagram)->create([
            'handle' => 'mineonly',
            'is_own_account' => true,
        ]);

        $watcher = User::factory()->create();
        TrackedAccount::factory()->for($watcher)->forPlatform(Platform::Instagram)->create([
            'handle' => 'mineonly',
        ]);

        $result = app(TrackedByService::class)->forUser($user->fresh(['brandProfile']));

        $this->assertSame(1, $result['count'] ?? null);
    }
}
