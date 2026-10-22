<?php

namespace Tests\Feature\Automations;

use App\Enums\Platform;
use App\Enums\TrackedAccountKind;
use App\Models\AutomationRun;
use App\Models\TrackedAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AutomationBriefCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_automation_brief_command_kicks_off_one_user(): void
    {
        config([
            'services.cursor_automations.brief.webhook_url' => 'https://api2.cursor.sh/automations/webhook/test-brief',
            'services.cursor_automations.brief.webhook_token' => 'brief-secret-token',
            'services.cursor_automations.mcp_url' => 'https://www.snitchsocial.net/mcp',
            'services.cursor_automations.trigger_guard_seconds' => 180,
        ]);

        Http::fake([
            'api2.cursor.sh/*' => Http::response([
                'success' => true,
                'backgroundComposerId' => 'bc-cmd-1',
                'runUuid' => 'run-cmd-1',
            ], 200),
        ]);

        $user = User::factory()->create();
        TrackedAccount::factory()->for($user)->create([
            'platform' => Platform::Instagram,
            'handle' => 'cmdclub',
            'kind' => TrackedAccountKind::Competitor,
        ]);

        $this->artisan('snitch:automation-brief', ['user' => $user->id])
            ->assertSuccessful();

        $this->assertSame(1, AutomationRun::query()->where('user_id', $user->id)->count());
        $this->assertSame(AutomationRun::STATUS_RUNNING, AutomationRun::query()->first()?->status);
    }

    public function test_admin_can_kickoff_brief_with_force(): void
    {
        config([
            'services.cursor_automations.brief.webhook_url' => 'https://api2.cursor.sh/automations/webhook/test-brief',
            'services.cursor_automations.brief.webhook_token' => 'brief-secret-token',
            'services.cursor_automations.mcp_url' => 'https://www.snitchsocial.net/mcp',
            'services.cursor_automations.trigger_guard_seconds' => 180,
        ]);

        Http::fake([
            'api2.cursor.sh/*' => Http::response([
                'success' => true,
                'backgroundComposerId' => 'bc-admin-1',
                'runUuid' => 'run-admin-1',
            ], 200),
        ]);

        $admin = User::factory()->create(['is_admin' => true]);
        $target = User::factory()->create();
        TrackedAccount::factory()->for($target)->create([
            'platform' => Platform::Instagram,
            'handle' => 'admintarget',
            'kind' => TrackedAccountKind::Competitor,
        ]);

        $this->actingAs($admin)
            ->post(route('admin.automation-runs.kickoff-brief'), [
                'user_id' => $target->id,
                'force' => true,
            ])
            ->assertRedirect(route('admin.automation-runs.index'));

        $this->assertSame(1, AutomationRun::query()->where('user_id', $target->id)->count());
    }
}
