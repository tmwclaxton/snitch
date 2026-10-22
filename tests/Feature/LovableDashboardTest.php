<?php

namespace Tests\Feature;

use App\Enums\Platform;
use App\Enums\PostType;
use App\Models\BrandProfile;
use App\Models\Post;
use App\Models\TrackedAccount;
use App\Models\User;
use App\Services\Dashboard\DashboardMetrics;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class LovableDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_canvas_returns_panels_for_selected_rival(): void
    {
        $user = User::factory()->create();
        BrandProfile::factory()->for($user)->create();

        $own = TrackedAccount::factory()->for($user)->create([
            'platform' => Platform::Instagram,
            'handle' => 'mybrand',
            'followers' => 800,
            'is_own_account' => true,
        ]);
        $rival = TrackedAccount::factory()->for($user)->create([
            'platform' => Platform::Instagram,
            'handle' => 'rivalbrand',
            'followers' => 1000,
            'is_own_account' => false,
        ]);

        Post::factory()->forAccount($rival)->create([
            'type' => PostType::Reel,
            'caption' => 'Shop the drop today?',
            'posted_at' => now()->subDay(),
            'metrics' => ['likes' => 80, 'comments' => 10, 'views' => 900],
        ]);
        Post::factory()->forAccount($own)->create([
            'type' => PostType::Image,
            'caption' => 'Hello from us',
            'posted_at' => now()->subDay(),
            'metrics' => ['likes' => 5, 'comments' => 1],
        ]);

        $this->actingAs($user)
            ->get(route('dashboard', ['accounts' => 'rivalbrand']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard')
                ->has('panels')
                ->where('panels_source', 'default')
                ->missing('own_account')
                ->missing('rivals')
            );

        $metrics = app(DashboardMetrics::class)->forUser($user, ['rivalbrand'], 30);
        $this->assertSame('mybrand', $metrics['own_account']['handle'] ?? null);
        $this->assertSame('rivalbrand', $metrics['selected'][0] ?? null);
        $this->assertArrayHasKey('status', $metrics['kpis'] ?? []);
        $this->assertArrayHasKey('status', $metrics['leaderboard'] ?? []);
    }
}
