<?php

namespace Tests\Feature\Mcp;

use App\Mcp\Servers\SnitchRegisterServer;
use App\Mcp\Tools\CreateAccountTool;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Mcp\Server\Testing\TestResponse;
use Tests\TestCase;

class CreateAccountToolTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_account_returns_token_and_claim_url(): void
    {
        /** @var TestResponse $response */
        $response = SnitchRegisterServer::tool(CreateAccountTool::class, [
            'name' => 'Agent Brand',
            'email' => 'agent-brand@example.com',
        ]);

        $response->assertOk();

        $user = User::query()->where('email', 'agent-brand@example.com')->first();
        $this->assertNotNull($user);
        $this->assertSame('mcp', $user->created_via);
        $this->assertNull($user->claimed_at);
        $this->assertNotNull($user->claim_token);
        $this->assertSame(0, (int) ($user->creditBalance?->balance_pence ?? 0));
        $this->assertNull($user->trial_ends_at);
        $this->assertFalse($user->onGenericTrial());
    }
}
