<?php

namespace Tests\Feature\Automations;

use App\Enums\Platform;
use App\Enums\TrackedAccountKind;
use App\Models\AutomationRun;
use App\Models\TrackedAccount;
use App\Models\User;
use App\Services\Automations\BriefAutomationKickoff;
use App\Services\Automations\CursorWebhookTriggerGuard;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BriefAutomationKickoffTest extends TestCase
{
    use RefreshDatabase;

    public function test_kickoff_is_noop_when_webhook_not_configured(): void
    {
        config([
            'services.cursor_automations.brief.webhook_url' => null,
            'services.cursor_automations.brief.webhook_token' => null,
        ]);

        $user = User::factory()->create();
        TrackedAccount::factory()->for($user)->create([
            'platform' => Platform::Instagram,
            'handle' => 'noopclub',
            'kind' => TrackedAccountKind::Competitor,
        ]);

        $result = app(BriefAutomationKickoff::class)->kickOff($user);

        $this->assertFalse($result['kicked_off']);
        $this->assertSame('not_configured', $result['reason']);
        $this->assertSame(0, AutomationRun::query()->count());
    }

    public function test_kickoff_refuses_placeholder_config(): void
    {
        config([
            'services.cursor_automations.brief.webhook_url' => 'https://example.com/webhook',
            'services.cursor_automations.brief.webhook_token' => 'YOUR_TOKEN_HERE',
        ]);

        $this->assertFalse(app(BriefAutomationKickoff::class)->configured());
    }

    public function test_kickoff_posts_payload_and_records_run(): void
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
                'backgroundComposerId' => 'bc-brief-1',
                'runUuid' => 'run-brief-1',
            ], 200),
        ]);

        $user = User::factory()->create();
        TrackedAccount::factory()->for($user)->create([
            'platform' => Platform::Instagram,
            'handle' => 'briefclub',
            'kind' => TrackedAccountKind::Competitor,
        ]);

        $result = app(BriefAutomationKickoff::class)->kickOff($user);

        $this->assertTrue($result['kicked_off']);
        $this->assertNotNull($result['run']);
        $this->assertSame(AutomationRun::STATUS_RUNNING, $result['run']->status);
        $this->assertSame('run-brief-1', $result['run']->cursor_run_id);
        $this->assertSame('bc-brief-1', $result['run']->cursor_background_composer_id);
        $this->assertNotNull($result['run']->personal_access_token_id);

        Http::assertSent(function ($request) use ($user): bool {
            $data = $request->data();

            return $request->url() === 'https://api2.cursor.sh/automations/webhook/test-brief'
                && $request->hasHeader('Authorization', 'Bearer brief-secret-token')
                && (int) ($data['user_id'] ?? 0) === (int) $user->id
                && ($data['mcp_url'] ?? null) === 'https://www.snitchsocial.net/mcp'
                && ($data['mode'] ?? null) === 'executive_brief'
                && is_string($data['scoped_token'] ?? null)
                && is_string($data['run_id'] ?? null)
                && in_array('briefclub', $data['handles'] ?? [], true);
        });

        $again = app(BriefAutomationKickoff::class)->kickOff($user);
        $this->assertFalse($again['kicked_off']);
        $this->assertSame('recently_triggered', $again['reason']);
        $this->assertTrue(app(CursorWebhookTriggerGuard::class)->recentlyTriggered(AutomationRun::TYPE_BRIEF, (int) $user->id));
    }

    public function test_schedule_registers_brief_automation_command(): void
    {
        $events = collect(app(Schedule::class)->events())
            ->map(fn ($event) => (string) ($event->command ?? $event->description ?? ''))
            ->implode(' ');

        $this->assertStringContainsString('snitch:kickoff-brief-automations', $events);
    }
}
