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
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WinnerScorerTest extends TestCase
{
    use RefreshDatabase;

    public function test_relative_multiplier_passes_at_threshold_and_fails_below(): void
    {
        $user = User::factory()->create();
        BrandProfile::factory()->for($user)->create();
        $account = TrackedAccount::factory()->for($user)->create();

        $priors = collect();
        $base = CarbonImmutable::now()->subDays(40);

        for ($i = 0; $i < 12; $i++) {
            $priors->push(Post::factory()->forAccount($account)->create([
                'posted_at' => $base->addDays($i),
                'metrics' => [
                    'views' => 1000,
                    'likes' => 50,
                    'comments' => 5,
                    'shares' => 0,
                ],
            ]));
        }

        $winner = Post::factory()->forAccount($account)->create([
            'posted_at' => $base->addDays(20),
            'metrics' => [
                'views' => 5000,
                'likes' => 200,
                'comments' => 20,
                'shares' => 0,
            ],
        ]);
        PostAnalysis::factory()->for($winner)->create([
            'status' => AnalysisStatus::Completed,
            'hook' => 'Open on the steam',
        ]);

        $flop = Post::factory()->forAccount($account)->create([
            'posted_at' => $base->addDays(21),
            'metrics' => [
                'views' => 800,
                'likes' => 40,
                'comments' => 2,
                'shares' => 0,
            ],
        ]);
        PostAnalysis::factory()->for($flop)->create([
            'status' => AnalysisStatus::Completed,
            'hook' => 'Quiet open',
        ]);

        $accountPosts = Post::query()
            ->where('social_account_id', $account->social_account_id)
            ->orderByDesc('posted_at')
            ->get();

        $rule = WinnerRule::factory()->for($user)->make([
            'preset' => 'balanced',
            'min_multiplier' => 2.0,
            'recency_days' => 90,
            'advanced' => ['require_hook' => true, 'require_sfx' => false, 'min_score' => 0],
        ]);

        $scorer = app(WinnerScorer::class);
        $winVerdict = $scorer->evaluate($winner, $rule, $accountPosts);
        $flopVerdict = $scorer->evaluate($flop, $rule, $accountPosts);

        $this->assertTrue($winVerdict['passes']);
        $this->assertNotNull($winVerdict['multiplier']);
        $this->assertGreaterThanOrEqual(2.0, (float) $winVerdict['multiplier']);
        $this->assertFalse($flopVerdict['passes']);
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
            'preset' => 'gentle',
            'min_multiplier' => 1.5,
            'recency_days' => 90,
            'advanced' => ['require_hook' => true, 'require_sfx' => false, 'min_score' => 0],
        ]);

        $base = CarbonImmutable::now()->subDays(40);

        for ($i = 0; $i < 12; $i++) {
            Post::factory()->forAccount($account)->create([
                'posted_at' => $base->addDays($i),
                'metrics' => ['views' => 1000, 'likes' => 40, 'comments' => 4, 'shares' => 0],
            ]);
        }

        $post = Post::factory()->forAccount($account)->create([
            'posted_at' => $base->addDays(20),
            'metrics' => ['views' => 5000, 'likes' => 400, 'comments' => 40, 'shares' => 10],
        ]);
        PostAnalysis::factory()->for($post)->create([
            'status' => AnalysisStatus::Completed,
            'hook' => 'Open on the steam',
            'how_to_copy' => null,
        ]);
        WinnerInsight::factory()->forPost($post)->create([
            'score' => 12.0,
            'performance_multiplier' => 3.0,
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
            'preset' => 'gentle',
            'min_multiplier' => 1.5,
            'recency_days' => 90,
            'advanced' => ['require_hook' => true, 'require_sfx' => false, 'min_score' => 0],
        ]);

        $base = CarbonImmutable::now()->subDays(40);

        for ($i = 0; $i < 12; $i++) {
            Post::factory()->forAccount($account)->create([
                'posted_at' => $base->addDays($i),
                'metrics' => ['views' => 1000, 'likes' => 40, 'comments' => 4, 'shares' => 0],
            ]);
        }

        $post = Post::factory()->forAccount($account)->create([
            'posted_at' => $base->addDays(20),
            'metrics' => ['views' => 5000, 'likes' => 400, 'comments' => 40, 'shares' => 10],
        ]);
        PostAnalysis::factory()->for($post)->create([
            'status' => AnalysisStatus::Completed,
            'hook' => 'Open on the steam',
            'how_to_copy' => 'Reuse the analysis remake plan instead of calling NanoGPT.',
        ]);

        $insight = app(WinnerScorer::class)->rescoreUser($user)->first();

        $this->assertNotNull($insight);
        $this->assertSame(
            'Reuse the analysis remake plan instead of calling NanoGPT.',
            $insight->how_to_copy,
        );
        $this->assertNotNull($insight->performance_multiplier);
        Http::assertNothingSent();
    }
}
