<?php

namespace Tests\Feature\Chat;

use App\Enums\Platform;
use App\Enums\TrackedAccountKind;
use App\Models\BrandProfile;
use App\Models\TrackedAccount;
use App\Models\User;
use App\Services\Chat\ChatToolRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InAppChatTest extends TestCase
{
    use RefreshDatabase;

    public function test_chat_page_requires_auth(): void
    {
        $this->get(route('chat.index'))->assertRedirect();
    }

    public function test_chat_page_renders_for_subscribed_user(): void
    {
        $user = User::factory()->create();
        BrandProfile::factory()->for($user)->create();

        $this->actingAs($user)
            ->get(route('chat.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('chat/Index')
                ->has('daily_cap')
                ->has('remaining')
                ->has('suggestions'));
    }

    public function test_chat_tools_only_return_the_authenticated_users_trackers(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();

        TrackedAccount::factory()->for($owner)->create([
            'platform' => Platform::Instagram,
            'handle' => 'ownclub',
            'kind' => TrackedAccountKind::Competitor,
        ]);
        TrackedAccount::factory()->for($other)->create([
            'platform' => Platform::Instagram,
            'handle' => 'secretclub',
            'kind' => TrackedAccountKind::Competitor,
        ]);

        $result = app(ChatToolRunner::class)->run($owner, 'list_trackers');
        $handles = collect($result['trackers'] ?? [])->pluck('handle')->all();

        $this->assertContains('ownclub', $handles);
        $this->assertNotContains('secretclub', $handles);
    }

    public function test_chat_tools_reject_unknown_tools(): void
    {
        $user = User::factory()->create();

        $this->expectException(\InvalidArgumentException::class);

        app(ChatToolRunner::class)->run($user, 'suggest_competitors', []);
    }
}
