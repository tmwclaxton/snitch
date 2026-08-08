<?php

namespace Tests\Unit\Winners;

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
use Tests\TestCase;

class WinnerScorerTest extends TestCase
{
    use RefreshDatabase;

    public function test_same_post_passes_aggressive_and_fails_conservative(): void
    {
        config([
            'snitch.nanogpt.api_key' => 'test-key',
            'snitch.nanogpt.base_url' => 'https://nano-gpt.test/api/v1',
        ]);

        Http::fake([
            'https://nano-gpt.test/api/v1/chat/completions' => Http::response([
                'choices' => [['message' => ['content' => '1) Hook hard 2) Show product 3) CTA']]],
            ]),
        ]);

        $user = User::factory()->create();
        BrandProfile::factory()->for($user)->create();
        $account = TrackedAccount::factory()->for($user)->create();
        $post = Post::factory()->forAccount($account)->create([
            'posted_at' => now()->subDay(),
            'metrics' => [
                'views' => 2500,
                'likes' => 180,
                'comments' => 20,
                'shares' => 8,
            ],
        ]);
        PostAnalysis::factory()->for($post)->create([
            'status' => AnalysisStatus::Completed,
            'hook' => 'Open on the steam',
        ]);

        $aggressive = WinnerRule::factory()->for($user)->make([
            ...(array) config('snitch.winners.presets.aggressive'),
            'preset' => 'aggressive',
            'advanced' => ['require_hook' => true, 'require_sfx' => false, 'min_score' => 10],
        ]);
        $conservative = WinnerRule::factory()->for($user)->make([
            ...(array) config('snitch.winners.presets.conservative'),
            'preset' => 'conservative',
            'advanced' => ['require_hook' => true, 'require_sfx' => false, 'min_score' => 10],
        ]);

        $scorer = app(WinnerScorer::class);

        $this->assertTrue($scorer->evaluate($post, $aggressive)['passes']);
        $this->assertFalse($scorer->evaluate($post, $conservative)['passes']);
    }

    public function test_rescore_reuses_existing_how_to_copy_without_llm(): void
    {
        config([
            'snitch.nanogpt.api_key' => 'test-key',
            'snitch.nanogpt.base_url' => 'https://nano-gpt.test/api/v1',
        ]);

        Http::fake([
            'https://nano-gpt.test/api/v1/chat/completions' => Http::response([
                'choices' => [['message' => ['content' => '1) Should not be used']]],
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

        $post = Post::factory()->forAccount($account)->create([
            'posted_at' => now()->subDay(),
            'metrics' => ['views' => 5000, 'likes' => 400, 'comments' => 40, 'shares' => 10],
        ]);
        PostAnalysis::factory()->for($post)->create([
            'status' => AnalysisStatus::Completed,
            'hook' => 'Open on the steam',
            'how_to_copy' => null,
        ]);
        WinnerInsight::factory()->forPost($post)->create([
            'score' => 12.0,
            'how_to_copy' => 'Keep this remake plan from the first score.',
        ]);

        $insight = app(WinnerScorer::class)->rescoreUser($user)->first();

        $this->assertNotNull($insight);
        $this->assertSame('Keep this remake plan from the first score.', $insight->how_to_copy);
        Http::assertNothingSent();
    }

    public function test_new_winner_uses_analysis_how_to_copy_without_llm(): void
    {
        config([
            'snitch.nanogpt.api_key' => 'test-key',
            'snitch.nanogpt.base_url' => 'https://nano-gpt.test/api/v1',
        ]);

        Http::fake([
            'https://nano-gpt.test/api/v1/chat/completions' => Http::response([
                'choices' => [['message' => ['content' => '1) Should not be used']]],
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

        $post = Post::factory()->forAccount($account)->create([
            'posted_at' => now()->subDay(),
            'metrics' => ['views' => 5000, 'likes' => 400, 'comments' => 40, 'shares' => 10],
        ]);
        PostAnalysis::factory()->for($post)->create([
            'status' => AnalysisStatus::Completed,
            'hook' => 'Open on the steam',
            'how_to_copy' => 'Open on steam. Cut to glaze. End on the pack shot.',
        ]);

        $insight = app(WinnerScorer::class)->rescoreUser($user)->first();

        $this->assertNotNull($insight);
        $this->assertSame(
            'Open on steam. Cut to glaze. End on the pack shot.',
            $insight->how_to_copy,
        );
        Http::assertNothingSent();
    }
}
