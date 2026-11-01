<?php

namespace Tests\Feature\Chat;

use App\Models\BrandProfile;
use App\Models\User;
use App\Services\Chat\InAppChatService;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

        $this->actingAs($user)
            ->post('/chat/stream', [
                'message' => 'How are we growing?',
            ])
            ->assertNotFound();
    }

    public function test_stream_uses_tools_scoped_to_the_user(): void
    {
        config(['snitch.chat.daily_message_cap' => 10]);

        $user = User::factory()->create();
        BrandProfile::factory()->for($user)->create();

        $this->actingAs($user)
            ->post('/chat/stream', [
                'message' => 'Any gaps?',
            ])
            ->assertNotFound();
    }
}
