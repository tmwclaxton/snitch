<?php

namespace Tests\Feature\Settings;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class McpSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_cannot_open_mcp_settings(): void
    {
        $this->get(route('settings.mcp.show'))
            ->assertRedirect(route('login'));
    }

    public function test_authenticated_users_can_open_mcp_settings(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('settings.mcp.show'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('settings/Mcp')
                ->has('mcp_url')
                ->has('clients')
                ->has('tools')
                ->where('has_mcp_token', false)
                ->where('plain_token', null));
    }

    public function test_mcp_settings_can_create_a_token(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('settings.mcp.token'))
            ->assertRedirect(route('settings.mcp.show'));

        $this->assertTrue($user->sanctumTokens()->where('name', 'mcp')->exists());

        $this->actingAs($user)
            ->get(route('settings.mcp.show'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('settings/Mcp')
                ->where('has_mcp_token', true)
                ->where('plain_token', fn ($token) => is_string($token) && $token !== ''));
    }

    public function test_signed_in_agents_page_redirects_to_mcp_settings(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('agents'))
            ->assertRedirect(route('settings.mcp.show'));
    }

    public function test_mcp_is_a_settings_subnav_item_not_product_sidebar(): void
    {
        $settings = file_get_contents(resource_path('js/layouts/settings/Layout.vue'));
        $sidebar = file_get_contents(resource_path('js/components/AppSidebar.vue'));

        $this->assertIsString($settings);
        $this->assertIsString($sidebar);
        $this->assertStringContainsString("title: 'MCP'", $settings);
        $this->assertStringNotContainsString("title: 'MCP'", $sidebar);
    }
}
