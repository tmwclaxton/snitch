<?php

namespace Tests\Feature\Mcp;

use App\Mcp\Servers\SnitchServer;
use App\Mcp\Support\WorkflowGuide;
use App\Mcp\Tools\GetDailyBriefTool;
use App\Models\DailyBrief;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GetDailyBriefToolTest extends TestCase
{
    use RefreshDatabase;

    public function test_get_daily_brief_returns_payload_and_app_url(): void
    {
        $user = User::factory()->create(['daily_brief_enabled' => true]);
        DailyBrief::factory()->for($user)->create([
            'headline' => 'Post a Reel tonight.',
        ]);
        $this->actingAs($user);

        SnitchServer::tool(GetDailyBriefTool::class)
            ->assertOk()
            ->assertSee('Post a Reel tonight.')
            ->assertSee('/today');
    }

    public function test_get_daily_brief_is_empty_when_none_exists(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        SnitchServer::tool(GetDailyBriefTool::class)
            ->assertOk()
            ->assertSee('"brief":null')
            ->assertSee('Do not force a regenerate');
    }

    public function test_workflow_guide_includes_daily_brief(): void
    {
        $guide = WorkflowGuide::for('daily_brief');

        $this->assertSame('daily_brief', $guide['workflow']);
        $this->assertTrue(collect($guide['steps'])->contains(fn (array $step): bool => $step['tool'] === 'get_daily_brief'));
    }
}
