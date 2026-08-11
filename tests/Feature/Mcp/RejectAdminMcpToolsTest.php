<?php

namespace Tests\Feature\Mcp;

use App\Mcp\Servers\SnitchServer;
use App\Models\BrandProfile;
use App\Models\User;
use App\Services\Billing\UsageBillingService;
use App\Support\AdminMcp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RejectAdminMcpToolsTest extends TestCase
{
    use RefreshDatabase;

    public function test_snitch_server_registers_no_admin_tools(): void
    {
        $reflection = new \ReflectionClass(SnitchServer::class);
        $property = $reflection->getProperty('tools');
        /** @var list<class-string> $toolClasses */
        $toolClasses = $property->getDefaultValue();

        foreach ($toolClasses as $class) {
            $this->assertStringNotContainsString(
                '\\Admin\\',
                $class,
                "Admin MCP tool class [{$class}] must not be registered on SnitchServer.",
            );

            $name = app($class)->name();
            $this->assertFalse(
                AdminMcp::isBlockedTool($name),
                "Registered MCP tool [{$name}] looks like an admin tool.",
            );
        }
    }

    public function test_admin_tool_names_are_rejected_over_http(): void
    {
        $user = User::factory()->create([
            'email' => 'tmwclaxton@gmail.com',
        ]);
        BrandProfile::factory()->for($user)->create();
        app(UsageBillingService::class)->creditClaimBonus($user);
        config(['snitch.admin_emails' => ['tmwclaxton@gmail.com']]);

        $this->assertTrue($user->isAdmin());

        $token = $user->createSanctumToken('mcp')->plainTextToken;

        foreach (['admin_overview', 'admin.overview', 'admin'] as $tool) {
            $this->withToken($token)
                ->postJson('/mcp', [
                    'jsonrpc' => '2.0',
                    'id' => 1,
                    'method' => 'tools/call',
                    'params' => [
                        'name' => $tool,
                        'arguments' => [],
                    ],
                ])
                ->assertForbidden()
                ->assertJsonPath('error.code', -32003)
                ->assertJsonPath('error.data.admin', true);
        }
    }
}
