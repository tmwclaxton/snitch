<?php

namespace Tests\Feature\Chat;

use App\Enums\Platform;
use App\Enums\TrackedAccountKind;
use App\Models\BrandProfile;
use App\Models\TrackedAccount;
use App\Models\User;
use App\Services\Chat\ChatSuggestionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ChatSuggestionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_chat_page_includes_four_data_backed_suggestions(): void
    {
        $user = User::factory()->create();
        BrandProfile::factory()->for($user)->create();
        TrackedAccount::factory()->for($user)->create([
            'platform' => Platform::Instagram,
            'handle' => 'ownclub',
            'is_own_account' => true,
            'kind' => TrackedAccountKind::Competitor,
        ]);
        TrackedAccount::factory()->for($user)->create([
            'platform' => Platform::Instagram,
            'handle' => 'rivalclub',
            'is_own_account' => false,
            'kind' => TrackedAccountKind::Competitor,
        ]);

        $this->actingAs($user)
            ->get(route('chat.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('chat/Index')
                ->has('suggestions', 4)
                ->where('suggestions.0', fn ($value) => is_string($value) && str_contains($value, 'ownclub')));
    }

    public function test_suggestion_service_returns_four_unique_prompts(): void
    {
        $user = User::factory()->create();
        $suggestions = app(ChatSuggestionService::class)->forUser($user);

        $this->assertCount(4, $suggestions);
        $this->assertCount(4, array_unique($suggestions));
    }
}
