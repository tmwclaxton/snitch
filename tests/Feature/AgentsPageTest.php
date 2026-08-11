<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AgentsPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    public function test_public_agents_page_renders_mcp_guide(): void
    {
        $this->get(route('agents'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('marketing/Agents')
                ->has('mcp_url')
                ->has('register_url')
                ->has('clients')
                ->has('general')
                ->has('tools'));
    }

    public function test_for_agents_redirects_to_agents(): void
    {
        $this->get('/for-agents')
            ->assertRedirect('/agents');
    }

    public function test_authenticated_agents_page_can_rotate_token(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('agents'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('agents/Index')
                ->where('has_mcp_token', false));

        $this->actingAs($user)
            ->post(route('agents.token'))
            ->assertRedirect();

        $this->assertTrue($user->sanctumTokens()->where('name', 'mcp')->exists());

        $this->actingAs($user)
            ->get(route('agents'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('agents/Index')
                ->where('has_mcp_token', true)
                ->where('plain_token', fn ($token) => is_string($token) && $token !== ''));
    }
}
