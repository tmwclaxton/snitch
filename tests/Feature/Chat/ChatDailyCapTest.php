<?php

namespace Tests\Feature\Chat;

use App\Models\BrandProfile;
use App\Models\User;
use App\Services\Analysis\NanoGptClient;
use App\Services\Chat\InAppChatService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class ChatDailyCapTest extends TestCase
{
    use RefreshDatabase;

    public function test_stream_returns_capped_event_when_daily_cap_hit(): void
    {
        config(['snitch.chat.daily_message_cap' => 2]);

        $user = User::factory()->create();
        BrandProfile::factory()->for($user)->create();

        $chat = app(InAppChatService::class);
        $chat->incrementUsage($user);
        $chat->incrementUsage($user);

        $this->assertTrue($chat->isCapped($user));

        $response = $this->actingAs($user)
            ->post(route('chat.stream'), [
                'message' => 'How are we growing?',
            ])
            ->assertOk()
            ->assertHeader('content-type', 'text/event-stream; charset=UTF-8');

        $body = $response->streamedContent();
        $this->assertStringContainsString('"type":"capped"', $body);
        $this->assertStringContainsString('Connect your AI', $body);
    }

    public function test_stream_uses_tools_scoped_to_the_user(): void
    {
        config(['snitch.chat.daily_message_cap' => 10]);

        $user = User::factory()->create();
        BrandProfile::factory()->for($user)->create();

        $nano = Mockery::mock(NanoGptClient::class);
        $nano->shouldReceive('chat')
            ->once()
            ->andReturn([
                'choices' => [[
                    'message' => [
                        'role' => 'assistant',
                        'content' => 'You have one quiet tracker this week.',
                    ],
                ]],
            ]);
        $nano->shouldReceive('extractAssistantText')
            ->once()
            ->andReturn('You have one quiet tracker this week.');
        $this->app->instance(NanoGptClient::class, $nano);

        $response = $this->actingAs($user)
            ->post(route('chat.stream'), [
                'message' => 'Any gaps?',
            ])
            ->assertOk();

        $this->assertStringContainsString('quiet tracker', $response->streamedContent());
    }
}
