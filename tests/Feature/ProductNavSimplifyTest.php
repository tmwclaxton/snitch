<?php

namespace Tests\Feature;

use App\Models\BrandProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class ProductNavSimplifyTest extends TestCase
{
    use RefreshDatabase;

    public function test_signed_ops_login_creates_session(): void
    {
        $user = User::factory()->create();
        BrandProfile::factory()->for($user)->create();

        $url = URL::temporarySignedRoute('ops.signed-login', now()->addMinutes(10), [
            'user' => $user->id,
        ]);

        $this->get($url)
            ->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($user);
        $this->assertSame('ops-signed-login', session('workos_access_token'));
    }

    public function test_chat_vue_uses_caution_tape_chat_shell(): void
    {
        $chat = file_get_contents(resource_path('js/pages/chat/Index.vue'));

        $this->assertNotFalse($chat);
        $this->assertStringContainsString('max-w-[760px]', $chat);
        $this->assertStringContainsString('Ask Snitch', $chat);
        $this->assertStringContainsString('font-hero', $chat);
        $this->assertStringContainsString('bg-[#FCD700]', $chat);
        $this->assertStringContainsString('ChatMarkdown', $chat);
        $this->assertStringContainsString('stickToBottom', $chat);
        $this->assertStringNotContainsString('—', $chat);
        $this->assertStringNotContainsString('–', $chat);
    }
}
