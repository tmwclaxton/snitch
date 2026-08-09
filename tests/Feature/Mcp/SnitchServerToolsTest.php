<?php

namespace Tests\Feature\Mcp;

use App\Mcp\Servers\SnitchServer;
use App\Mcp\Tools\BillingPortalTool;
use App\Mcp\Tools\ExplorePostsTool;
use App\Mcp\Tools\RemoveCompetitorTool;
use App\Mcp\Tools\UpdateWinnerRulesTool;
use App\Models\User;
use App\Models\WinnerRule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SnitchServerToolsTest extends TestCase
{
    use RefreshDatabase;

    public function test_update_winner_rules_tool_persists_preset(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = SnitchServer::tool(UpdateWinnerRulesTool::class, [
            'preset' => 'aggressive',
        ]);

        $response->assertOk();

        $this->assertDatabaseHas('winner_rules', [
            'user_id' => $user->id,
            'preset' => 'aggressive',
        ]);
    }

    public function test_explore_posts_tool_returns_posts_payload(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = SnitchServer::tool(ExplorePostsTool::class, [
            'limit' => 5,
        ]);

        $response->assertOk();
    }

    public function test_remove_competitor_tool_errors_when_missing(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = SnitchServer::tool(RemoveCompetitorTool::class, [
            'tracked_account_id' => 999999,
        ]);

        $response->assertHasErrors();
    }

    public function test_billing_portal_tool_requires_stripe_customer(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = SnitchServer::tool(BillingPortalTool::class);

        $response->assertHasErrors();
    }

    public function test_snitch_server_registers_expected_tool_count(): void
    {
        $reflection = new \ReflectionClass(SnitchServer::class);
        $property = $reflection->getProperty('tools');
        /** @var list<class-string> $tools */
        $tools = $property->getDefaultValue();

        $this->assertGreaterThanOrEqual(30, count($tools));
        $this->assertContains(UpdateWinnerRulesTool::class, $tools);
        $this->assertContains(ExplorePostsTool::class, $tools);
        $this->assertContains(BillingPortalTool::class, $tools);
    }

    public function test_update_winner_rules_creates_rule_row(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        SnitchServer::tool(UpdateWinnerRulesTool::class, [
            'preset' => 'balanced',
            'min_views' => 2500,
        ])->assertOk();

        $rule = WinnerRule::query()->where('user_id', $user->id)->first();
        $this->assertNotNull($rule);
        $this->assertSame(2500, $rule->min_views);
    }
}
