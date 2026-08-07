<?php

namespace Tests\Feature;

use App\Enums\AnalysisStatus;
use App\Models\BrandProfile;
use App\Models\Post;
use App\Models\PostAnalysis;
use App\Models\TrackedAccount;
use App\Models\User;
use App\Models\WinnerInsight;
use App\Models\WinnerRule;
use App\Services\Winners\WinnerScorer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class WinnersTest extends TestCase
{
    use RefreshDatabase;

    public function test_winners_page_only_shows_matching_posts(): void
    {
        config([
            'snitch.nanogpt.api_key' => 'test-key',
            'snitch.nanogpt.base_url' => 'https://nano-gpt.test/api/v1',
        ]);

        Http::fake([
            'https://nano-gpt.test/api/v1/chat/completions' => Http::response([
                'choices' => [['message' => ['content' => '1) Remake the hook 2) Keep the pace 3) End on CTA']]],
            ]),
        ]);

        $user = User::factory()->create();
        BrandProfile::factory()->for($user)->create();
        $account = TrackedAccount::factory()->for($user)->create();

        WinnerRule::factory()->for($user)->create([
            'preset' => 'aggressive',
            ...(array) config('snitch.winners.presets.aggressive'),
            'advanced' => ['require_hook' => true, 'require_sfx' => false, 'min_score' => 5],
        ]);

        $winnerPost = Post::factory()->forAccount($account)->create([
            'posted_at' => now()->subDay(),
            'metrics' => ['views' => 5000, 'likes' => 400, 'comments' => 40, 'shares' => 10],
        ]);
        PostAnalysis::factory()->for($winnerPost)->create([
            'status' => AnalysisStatus::Completed,
            'hook' => 'Strong opening line here',
        ]);

        $loserPost = Post::factory()->forAccount($account)->create([
            'posted_at' => now()->subDay(),
            'metrics' => ['views' => 10, 'likes' => 1, 'comments' => 0, 'shares' => 0],
        ]);
        PostAnalysis::factory()->for($loserPost)->create([
            'status' => AnalysisStatus::Completed,
            'hook' => 'Weak post hook xx',
        ]);

        app(WinnerScorer::class)->rescoreUser($user);

        $this->assertTrue(
            WinnerInsight::query()->where('post_id', $winnerPost->id)->exists(),
        );
        $this->assertFalse(
            WinnerInsight::query()->where('post_id', $loserPost->id)->exists(),
        );

        $this->actingAs($user)
            ->get(route('winners.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('winners/Index')
                ->has('winners', 1)
                ->where('winners.0.post.id', $winnerPost->id)
                ->has('presets')
                ->has('presets.balanced')
                ->has('rule.preset')
                ->has('rule.min_views')
                ->has('rule.min_likes')
                ->has('rule.min_engagement_rate')
                ->has('rule.advanced')
            );
    }

    public function test_winner_rules_can_be_updated(): void
    {
        $user = User::factory()->create();
        BrandProfile::factory()->for($user)->create();

        WinnerRule::factory()->for($user)->create([
            'preset' => 'balanced',
            ...(array) config('snitch.winners.presets.balanced'),
        ]);

        $this->actingAs($user)
            ->put(route('winners.rules.update'), [
                'preset' => 'aggressive',
                'min_views' => 200,
                'min_likes' => 20,
                'min_engagement_rate' => 1,
                'recency_days' => 60,
                'advanced' => [
                    'require_hook' => true,
                    'require_sfx' => false,
                    'min_score' => 40,
                ],
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('winner_rules', [
            'user_id' => $user->id,
            'preset' => 'aggressive',
            'min_views' => 200,
            'min_likes' => 20,
        ]);
    }
}
