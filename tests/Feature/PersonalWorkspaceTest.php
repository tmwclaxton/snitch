<?php

namespace Tests\Feature;

use App\Models\BrandProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PersonalWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_route_has_no_team_slug(): void
    {
        $this->assertSame(url('/dashboard'), route('dashboard'));
    }

    public function test_team_settings_routes_are_removed(): void
    {
        $this->assertFalse(
            collect(Route::getRoutes())->contains(
                fn ($route) => str_contains($route->getName() ?? '', 'teams.'),
            ),
        );
    }

    public function test_authenticated_user_reaches_personal_dashboard(): void
    {
        $user = User::factory()->create();
        BrandProfile::factory()->for($user)->create();

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertOk();
    }

    public function test_users_table_has_no_current_team_id(): void
    {
        $user = User::factory()->create();

        $this->assertArrayNotHasKey('current_team_id', $user->getAttributes());
        $this->assertFalse(Schema::hasColumn('users', 'current_team_id'));
        $this->assertFalse(Schema::hasTable('teams'));
    }
}
