<?php

namespace Tests\Feature\Mcp;

use App\Mcp\Servers\SnitchServer;
use App\Mcp\Tools\WorkflowGuideTool;
use App\Models\BrandProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class WorkflowGuideToolTest extends TestCase
{
    use RefreshDatabase;

    public function test_server_instructions_mention_workflow_guide(): void
    {
        $attributes = (new \ReflectionClass(SnitchServer::class))->getAttributes(Instructions::class);
        $this->assertNotEmpty($attributes);

        $instructions = $attributes[0]->newInstance()->value;

        $this->assertStringContainsString('workflow_guide', $instructions);
        $this->assertStringContainsString('wait_seconds', $instructions);
    }

    public function test_workflow_guide_returns_owner_loops_and_next_step(): void
    {
        config(['app.url' => 'http://localhost:8000']);

        $user = User::factory()->create();
        BrandProfile::factory()->for($user)->create([
            'name' => 'FarmDrive',
            'website' => 'https://farmdrive.io/',
            'description' => 'Livestock management app.',
        ]);
        Sanctum::actingAs($user);

        SnitchServer::tool(WorkflowGuideTool::class)
            ->assertOk()
            ->assertSee('confirm_competitor_suggestions')
            ->assertSee('keep_influencer')
            ->assertSee('next_step')
            ->assertSee('FarmDrive')
            ->assertSee('suggest_competitors');
    }

    public function test_workflow_guide_topic_filters_flows(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        SnitchServer::tool(WorkflowGuideTool::class, [
            'topic' => 'competitors',
        ])
            ->assertOk()
            ->assertSee('confirm_competitor_suggestions')
            ->assertSee('"topic":"competitors"');
    }
}
