<?php

namespace Tests\Feature\Automations;

use App\Models\BrandProfile;
use App\Models\User;
use App\Services\Analysis\NanoGptClient;
use App\Services\Automations\ChatAutomationKickoff;
use App\Services\Billing\UsageBillingService;
use App\Services\Chat\InAppChatService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Mockery;
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
            ->get(route('chat.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('chat/Index')
                ->where('automation_enabled', true)
                ->where('automation_timeout_seconds', 180)
                ->where('daily_cap', app(InAppChatService::class)->dailyCap())
            );
    }

    public function test_chat_stream_uses_llm_when_automation_not_configured(): void
    {
        config([
            'services.cursor_automations.chat.webhook_url' => null,
            'services.cursor_automations.chat.webhook_token' => null,
        ]);

        $nano = Mockery::mock(NanoGptClient::class);
        $nano->shouldReceive('chat')->once()->andReturn([
            'choices' => [
                [
                    'message' => [
                        'role' => 'assistant',
                        'content' => 'Fallback LLM reply.',
                    ],
                ],
            ],
        ]);
        $nano->shouldReceive('extractAssistantText')->once()->andReturn('Fallback LLM reply.');
        $this->app->instance(NanoGptClient::class, $nano);

        $user = User::factory()->create();
        BrandProfile::factory()->for($user)->create();
        app(UsageBillingService::class)->creditClaimBonus($user);

        $this->assertFalse(app(ChatAutomationKickoff::class)->configured());

        $response = $this->actingAs($user)->post(route('chat.stream'), [
            'message' => 'What should I post?',
            'history' => [],
        ]);

        $response->assertOk();
        $body = $response->streamedContent();
        $this->assertStringContainsString('Fallback LLM reply.', $body);
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

        $this->assertTrue($result['kicked_off']);
        Http::assertSent(function ($request) use ($user): bool {
            $data = $request->data();

            return ($data['mode'] ?? null) === 'in_app_chat'
                && (int) ($data['user_id'] ?? 0) === (int) $user->id
                && ($data['message'] ?? null) === 'What should I post?'
                && is_string($data['scoped_token'] ?? null)
                && ($data['mcp_url'] ?? null) === 'https://www.snitchsocial.net/mcp';
        });
    }
}
