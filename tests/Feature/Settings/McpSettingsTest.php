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
            ->get('/settings/mcp')
            ->assertRedirect(route('connect.show'));

        $this->actingAs($user)
            ->get(route('connect.show'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('connect/Index')
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
            ->post(route('connect.token'))
            ->assertRedirect(route('connect.show'));

        $this->assertTrue($user->sanctumTokens()->where('name', 'mcp')->exists());

        $this->actingAs($user)
            ->get(route('connect.show'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('connect/Index')
                ->where('has_mcp_token', true)
                ->where('plain_token', fn ($token) => is_string($token) && $token !== ''));
    }

    public function test_signed_in_agents_page_redirects_to_mcp_settings(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('agents'))
            ->assertRedirect(route('connect.show'));
    }

    public function test_mcp_is_in_product_sidebar_and_not_settings_subnav(): void
    {
        $settings = file_get_contents(resource_path('js/layouts/settings/Layout.vue'));
        $sidebar = file_get_contents(resource_path('js/components/AppSidebar.vue'));
        $page = file_get_contents(resource_path('js/pages/connect/Index.vue'));
        $endpoints = file_get_contents(resource_path('js/components/agents/McpEndpoints.vue'));

        $this->assertIsString($settings);
        $this->assertIsString($sidebar);
        $this->assertIsString($page);
        $this->assertIsString($endpoints);
        $this->assertStringContainsString("title: 'MCP'", $sidebar);
        $this->assertStringContainsString("title: 'Profile'", $settings);
        $this->assertStringNotContainsString("title: 'MCP'", $settings);
        $this->assertStringContainsString('max-w-6xl', $page);
        $this->assertStringContainsString('text-sm', $page);
        $this->assertStringNotContainsString('truncate', $page);
        $this->assertStringContainsString('break-all', $endpoints);
        $this->assertStringNotContainsString('text-xs', $endpoints);
        $this->assertStringNotContainsString('snitch-ink-label', $endpoints);
    }
}
