<?php

namespace Tests\Feature\Automations;

use App\Models\BrandProfile;
use App\Models\User;
use App\Services\Automations\ChatAutomationKickoff;
use App\Services\Billing\UsageBillingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ChatAutomationFallbackTest extends TestCase
{
    use RefreshDatabase;

    public function test_chat_page_exposes_automation_flags_when_configured(): void
    {
        config([
            'services.cursor_automations.chat.webhook_url' => 'https://api2.cursor.sh/automations/webhook/test-chat',
            'services.cursor_automations.chat.webhook_token' => 'chat-secret-token',
            'services.cursor_automations.chat.timeout_seconds' => 180,
        ]);

        $user = User::factory()->create();
        BrandProfile::factory()->for($user)->create();
        app(UsageBillingService::class)->creditClaimBonus($user);

        $this->actingAs($user)
            ->get('/chat')
            ->assertRedirect('/dashboard');
    }

    public function test_chat_stream_uses_llm_when_automation_not_configured(): void
    {
        config([
            'services.cursor_automations.chat.webhook_url' => null,
            'services.cursor_automations.chat.webhook_token' => null,
        ]);

        $user = User::factory()->create();
        BrandProfile::factory()->for($user)->create();
        app(UsageBillingService::class)->creditClaimBonus($user);

        $this->assertFalse(app(ChatAutomationKickoff::class)->configured());

        $this->actingAs($user)->post('/chat/stream', [
            'message' => 'What should I post?',
            'history' => [],
        ])->assertNotFound();
    }

    public function test_chat_kickoff_sends_conversation_payload(): void
    {
        config([
            'services.cursor_automations.chat.webhook_url' => 'https://api2.cursor.sh/automations/webhook/test-chat',
            'services.cursor_automations.chat.webhook_token' => 'chat-secret-token',
            'services.cursor_automations.mcp_url' => 'https://www.snitchsocial.net/mcp',
        ]);

        Http::fake([
            'api2.cursor.sh/*' => Http::response([
                'success' => true,
                'backgroundComposerId' => 'bc-chat-1',
            ], 200),
        ]);

        $user = User::factory()->create();
        $result = app(ChatAutomationKickoff::class)->kickOff($user, 'What should I post?', [
            ['role' => 'user', 'content' => 'Earlier question'],
            ['role' => 'assistant', 'content' => 'Earlier answer'],
        ]);

        $this->assertFalse($result['kicked_off']);
        $this->assertSame('removed', $result['reason']);
        Http::assertNothingSent();
    }
}
