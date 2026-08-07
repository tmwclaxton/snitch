<?php

namespace Tests\Unit\Winners;

use App\Enums\AnalysisStatus;
use App\Models\BrandProfile;
use App\Models\Post;
use App\Models\PostAnalysis;
use App\Models\TrackedAccount;
use App\Models\User;
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
}
