<?php

namespace Tests\Feature\Mcp;

use App\Models\BrandProfile;
use App\Models\McpToolInvocation;
use App\Models\User;
use App\Services\Billing\UsageBillingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class McpToolInvocationLoggingTest extends TestCase
{
    use RefreshDatabase;

    public function test_http_tools_call_writes_invocation_row(): void
    {
        $user = User::factory()->create();
        BrandProfile::factory()->for($user)->create();
        app(UsageBillingService::class)->creditClaimBonus($user);
        $token = $user->createSanctumToken('mcp')->plainTextToken;

        $this->withToken($token)
            ->postJson('/mcp', [
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'tools/call',
                'params' => [
                    'name' => 'whoami',
                    'arguments' => [],
                ],
            ])
            ->assertSuccessful();

        $this->assertDatabaseHas('mcp_tool_invocations', [
            'user_id' => $user->id,
            'tool' => 'whoami',
            'ok' => true,
            'auth' => 'sanctum',
        ]);

        $row = McpToolInvocation::query()->where('tool', 'whoami')->first();
        $this->assertNotNull($row);
        $this->assertNotNull($row->duration_ms);
    }

    public function test_paywalled_tools_call_logs_failure(): void
    {
        $user = User::factory()->withoutStarterCredit()->create();
        BrandProfile::factory()->for($user)->create();
        $token = $user->createSanctumToken('mcp')->plainTextToken;

        $this->withToken($token)
            ->postJson('/mcp', [
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'tools/call',
                'params' => [
                    'name' => 'list_feed',
                    'arguments' => ['limit' => 5],
                ],
            ])
            ->assertStatus(402);

        $this->assertDatabaseHas('mcp_tool_invocations', [
            'user_id' => $user->id,
            'tool' => 'list_feed',
            'ok' => false,
            'error_code' => '-32001',
        ]);
    }
}
