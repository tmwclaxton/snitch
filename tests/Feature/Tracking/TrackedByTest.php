<?php

namespace Tests\Feature\Tracking;

use App\Enums\Platform;
use App\Models\BrandProfile;
use App\Models\TrackedAccount;
use App\Models\User;
use App\Services\Tracking\TrackedByService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TrackedByTest extends TestCase
{
    use RefreshDatabase;

    public function test_tracked_by_service_returns_counts_only(): void
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

        $payload = app(TrackedByService::class)->forUser($user);

        $this->assertSame(1, $payload['count'] ?? null);
        $this->assertArrayHasKey('since', $payload);
        $this->assertArrayNotHasKey('watchers', $payload);
        $this->assertArrayNotHasKey('users', $payload);

        $source = file_get_contents(resource_path('js/components/dashboard/TrackedBySection.vue'));
        $this->assertIsString($source);
        $this->assertStringContainsString('Counts only', $source);
        $this->assertStringContainsString('#0E0E10', $source);
        $this->assertStringContainsString('#FCD700', $source);
        $this->assertStringContainsString('#FF3D8B', $source);
        $this->assertStringContainsString('caution-tape-headline', $source);
        $this->assertStringNotContainsString('watcher email', $source);
    }

    public function test_tracked_by_service_is_null_when_count_is_zero(): void
    {
        $user = User::factory()->create();
        BrandProfile::factory()->for($user)->create([
            'own_handles' => ['instagram' => '@quietbrand'],
        ]);

        $payload = app(TrackedByService::class)->forUser($user);

        $this->assertNull($payload);
    }

    public function test_curated_dashboard_does_not_expose_tracked_by_prop(): void
    {
        $user = User::factory()->create();
        BrandProfile::factory()->for($user)->create([
            'own_handles' => ['instagram' => '@quietbrand'],
        ]);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Dashboard')
                ->has('panels')
                ->missing('trackedBy')
            );
    }
}
