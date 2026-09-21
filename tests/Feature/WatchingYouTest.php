<?php

namespace Tests\Feature;

use App\Models\BrandProfile;
use App\Models\TrackedAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class WatchingYouTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_lists_brand_handle_watch_status(): void
    {
        $user = User::factory()->create();
        BrandProfile::factory()->for($user)->create([
            'own_handles' => ['instagram' => '@loaflocal'],
        ]);
        $watcher = User::factory()->create();
        TrackedAccount::factory()->for($watcher)->create(['handle' => 'loaflocal']);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard')
                ->where('watching.brand.0.handle', 'loaflocal')
                ->where('watching.brand.0.watched', true)
                ->where('watching.brand.0.watcher_count', 1)
                ->where('watching.check', null)
            );
    }

    public function test_user_can_check_a_handle_without_seeing_who_is_watching(): void
    {
        $user = User::factory()->create();
        BrandProfile::factory()->for($user)->create();
        $watcher = User::factory()->create();
        TrackedAccount::factory()->for($watcher)->create(['handle' => 'secretbrand']);

        $this->actingAs($user)
            ->from(route('dashboard'))
            ->post(route('dashboard.watching'), ['handle' => '@secretbrand'])
            ->assertRedirect(route('dashboard'));

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard')
                ->where('watching.check.handle', 'secretbrand')
                ->where('watching.check.watched', true)
                ->where('watching.check.watcher_count', 1)
                ->missing('watching.check.user_id')
            );

        $dashboard = file_get_contents(resource_path('js/pages/Dashboard.vue'));
        $this->assertIsString($dashboard);
        $this->assertStringNotContainsString('Am I being tracked?', $dashboard);
        $this->assertStringNotContainsString('WatchingYouModal', $dashboard);
        $this->assertStringNotContainsString('Watchers', $dashboard);
        $this->assertStringNotContainsString('never who', $dashboard);
        $this->assertStringNotContainsString('watcher email', $dashboard);
    }

    public function test_check_requires_a_handle(): void
    {
        $user = User::factory()->create();
        BrandProfile::factory()->for($user)->create();

        $this->actingAs($user)
            ->from(route('dashboard'))
            ->post(route('dashboard.watching'), ['handle' => ''])
            ->assertRedirect(route('dashboard'))
            ->assertSessionHasErrors('handle');
    }
}
