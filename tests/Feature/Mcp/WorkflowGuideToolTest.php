<?php

namespace Tests\Feature\Mcp;

use App\Mcp\Servers\SnitchServer;
use App\Mcp\Support\WorkflowGuide;
use App\Mcp\Tools\WorkflowGuideTool;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Mcp\Server\Attributes\Instructions;
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
    }

    public function test_workflow_guide_overview_lists_core_loops(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        SnitchServer::tool(WorkflowGuideTool::class)
            ->assertOk()
            ->assertSee('whoami')
            ->assertSee('billing_status')
            ->assertSee('available_workflows');
    }

    public function test_workflow_guide_competitors_requires_confirm(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        SnitchServer::tool(WorkflowGuideTool::class, [
            'workflow' => 'competitors',
        ])
            ->assertOk()
            ->assertSee('confirm_competitor_suggestions')
            ->assertSee('"workflow":"competitors"')
            ->assertSee('wait_seconds');
    }

    public function test_workflow_guide_accepts_topic_alias(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        SnitchServer::tool(WorkflowGuideTool::class, [
            'topic' => 'brand',
        ])
            ->assertOk()
            ->assertSee('"workflow":"brand"')
            ->assertSee('start_brand_autofill')
            ->assertSee('does NOT clear');
    }

    public function test_workflow_guide_influencers_mentions_latest_run_pointer(): void
    {
        $guide = WorkflowGuide::for('influencers');

        $this->assertSame('influencers', $guide['workflow']);
        $this->assertTrue(collect($guide['notes'])->contains(
            fn (string $note): bool => str_contains($note, 'latest'),
        ));
    }

    public function test_workflow_guide_content_plan_mentions_winners_and_dismiss(): void
    {
        $guide = WorkflowGuide::for('content_plan');

        $this->assertSame('content_plan', $guide['workflow']);
        $this->assertTrue(collect($guide['steps'])->contains(
            fn (array $step): bool => ($step['tool'] ?? null) === 'list_winners',
        ));
        $this->assertTrue(collect($guide['steps'])->contains(
            fn (array $step): bool => ($step['tool'] ?? null) === 'dismiss_influencer_suggestions',
        ));
    }

    public function test_server_instructions_mention_content_plan_and_snitch_url(): void
    {
        $attributes = (new \ReflectionClass(SnitchServer::class))->getAttributes(Instructions::class);
        $instructions = $attributes[0]->newInstance()->value;

        $this->assertStringContainsString('content_plan', $instructions);
        $this->assertStringContainsString('dismiss_influencer_suggestions', $instructions);
        $this->assertStringContainsString('snitch_url', $instructions);
    }
}
