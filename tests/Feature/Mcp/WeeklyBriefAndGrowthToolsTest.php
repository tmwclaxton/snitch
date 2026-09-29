<?php

namespace Tests\Feature\Mcp;

use App\Mcp\Servers\SnitchServer;
use App\Mcp\Support\WorkflowGuide;
use App\Mcp\Tools\GetGrowthTool;
use App\Mcp\Tools\GetMonthlyReportTool;
use App\Mcp\Tools\GetWeeklyBriefTool;
use App\Mcp\Tools\MarkWeeklyBriefIdeaUsedTool;
use App\Mcp\Tools\RevokeMonthlyReportTool;
use App\Mcp\Tools\ShareMonthlyReportTool;
use App\Models\MonthlyReport;
use App\Models\TrackedAccount;
use App\Models\User;
use App\Models\WeeklyBrief;
use App\Models\WeeklyBriefIdea;
use App\Services\Brief\WeeklyBriefGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WeeklyBriefAndGrowthToolsTest extends TestCase
{
    use RefreshDatabase;

    public function test_get_weekly_brief_returns_ideas_and_app_url(): void
    {
        $user = User::factory()->create();
        $week = app(WeeklyBriefGenerator::class)->currentWeekStart();
        $brief = WeeklyBrief::factory()->for($user)->create([
            'week_start' => $week->toDateString(),
            'best_times' => [['day' => 'Tue', 'hour' => 11]],
        ]);
        WeeklyBriefIdea::factory()->create([
            'weekly_brief_id' => $brief->id,
            'hook' => 'Open with the receipt',
        ]);
        $this->actingAs($user);

        SnitchServer::tool(GetWeeklyBriefTool::class)
            ->assertOk()
            ->assertSee('Open with the receipt')
            ->assertSee('/brief')
            ->assertSee($week->toDateString());
    }

    public function test_get_weekly_brief_is_empty_when_none_exists(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        SnitchServer::tool(GetWeeklyBriefTool::class)
            ->assertOk()
            ->assertSee('"brief":null')
            ->assertSee('Do not force a regenerate');
    }

    public function test_mark_weekly_brief_idea_used_toggles(): void
    {
        $user = User::factory()->create();
        $brief = WeeklyBrief::factory()->for($user)->create();
        $idea = WeeklyBriefIdea::factory()->create([
            'weekly_brief_id' => $brief->id,
        ]);
        $this->actingAs($user);

        SnitchServer::tool(MarkWeeklyBriefIdeaUsedTool::class, [
            'idea_id' => $idea->id,
        ])->assertOk()->assertSee('"used":true');

        $this->assertNotNull($idea->fresh()->used_at);

        SnitchServer::tool(MarkWeeklyBriefIdeaUsedTool::class, [
            'idea_id' => $idea->id,
        ])->assertOk()->assertSee('"used":false');

        $this->assertNull($idea->fresh()->used_at);
    }

    public function test_mark_weekly_brief_idea_used_rejects_another_users_idea(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $brief = WeeklyBrief::factory()->for($other)->create();
        $idea = WeeklyBriefIdea::factory()->create([
            'weekly_brief_id' => $brief->id,
        ]);
        $this->actingAs($user);

        SnitchServer::tool(MarkWeeklyBriefIdeaUsedTool::class, [
            'idea_id' => $idea->id,
        ])->assertHasErrors();

        $this->assertNull($idea->fresh()->used_at);
    }

    public function test_get_growth_returns_tracked_handle(): void
    {
        $user = User::factory()->create();
        TrackedAccount::factory()->for($user)->create([
            'handle' => 'growthbrand',
            'is_own_account' => true,
        ]);
        $this->actingAs($user);

        SnitchServer::tool(GetGrowthTool::class, [
            'period' => '30d',
        ])
            ->assertOk()
            ->assertSee('"period":"30d"')
            ->assertSee('growthbrand')
            ->assertSee('/growth?period=30d');
    }

    public function test_monthly_report_can_be_read_shared_and_revoked(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        SnitchServer::tool(GetMonthlyReportTool::class)
            ->assertOk()
            ->assertSee('month_label')
            ->assertSee('/growth/report');

        $reportId = MonthlyReport::query()->where('user_id', $user->id)->value('id');
        $this->assertNotNull($reportId);

        SnitchServer::tool(ShareMonthlyReportTool::class, [
            'report_id' => $reportId,
        ])->assertOk()->assertSee('/r/');

        SnitchServer::tool(RevokeMonthlyReportTool::class, [
            'report_id' => $reportId,
        ])->assertOk()->assertSee('"revoked":true');
    }

    public function test_share_monthly_report_requires_an_existing_report_when_month_omitted(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        SnitchServer::tool(ShareMonthlyReportTool::class)
            ->assertHasErrors();
    }

    public function test_workflow_weekly_brief_and_growth_are_listed(): void
    {
        $brief = WorkflowGuide::for('weekly_brief');
        $growth = WorkflowGuide::for('growth');

        $this->assertSame('weekly_brief', $brief['workflow']);
        $this->assertTrue(collect($brief['steps'])->contains(
            fn (array $step): bool => ($step['tool'] ?? null) === 'get_weekly_brief',
        ));
        $this->assertSame('growth', $growth['workflow']);
        $this->assertTrue(collect($growth['steps'])->contains(
            fn (array $step): bool => ($step['tool'] ?? null) === 'get_monthly_report',
        ));
    }
}
