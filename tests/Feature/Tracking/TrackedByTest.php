<?php

namespace Tests\Feature\Tracking;

use App\Enums\Platform;
use App\Models\BrandProfile;
use App\Models\TrackedAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class TrackedByTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_exposes_tracked_by_counts_only(): void
    {
        $user = User::factory()->create();
        BrandProfile::factory()->for($user)->create([
            'own_handles' => ['instagram' => '@watchedbrand'],
        ]);
        $watcher = User::factory()->create();
        TrackedAccount::factory()->for($watcher)->forPlatform(Platform::Instagram)->create([
            'handle' => 'watchedbrand',
            'created_at' => now()->subDays(5),
        ]);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard')
                ->where('trackedBy.count', 1)
                ->has('trackedBy.since')
                ->missing('trackedBy.watchers')
                ->missing('trackedBy.users')
            );

        $source = file_get_contents(resource_path('js/components/dashboard/TrackedBySection.vue'));
        $this->assertIsString($source);
        $this->assertStringContainsString('Counts only', $source);
        $this->assertStringNotContainsString('watcher email', $source);
    }

    public function test_dashboard_tracked_by_is_null_when_count_is_zero(): void
    {
        $user = User::factory()->create();
        BrandProfile::factory()->for($user)->create([
            'own_handles' => ['instagram' => '@quietbrand'],
        ]);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard')
                ->where('trackedBy', null)
            );
    }
}
