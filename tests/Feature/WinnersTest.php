<?php

namespace Tests\Feature;

use App\Enums\AnalysisStatus;
use App\Enums\Platform;
use App\Jobs\ScoreWinnersJob;
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
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class WinnersTest extends TestCase
{
    use RefreshDatabase;

    public function test_winners_index_redirects_to_dashboard_performance(): void
    {
        $user = User::factory()->create();
        BrandProfile::factory()->for($user)->create();

        $this->actingAs($user)
            ->get(route('winners.index'))
            ->assertRedirect(route('dashboard').'#performance');

        $dashboard = file_get_contents(resource_path('js/pages/Dashboard.vue'));
        $this->assertIsString($dashboard);
        $this->assertStringContainsString('id="performance"', $dashboard);
        $this->assertStringContainsString('id="winners"', $dashboard);
    }

    public function test_winner_scorer_still_persists_matching_posts(): void
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
        $account = TrackedAccount::factory()->for($user)->create([
            'platform' => Platform::TikTok,
        ]);

        WinnerRule::factory()->for($user)->create([
            'preset' => 'balanced',
            ...(array) config('snitch.winners.presets.balanced'),
            'recency_days' => 90,
            'advanced' => ['require_hook' => true, 'require_sfx' => false, 'min_score' => 0],
        ]);

        $base = CarbonImmutable::now()->subDays(40);

        for ($i = 0; $i < 12; $i++) {
            Post::factory()->forAccount($account)->create([
                'platform' => Platform::TikTok,
                'posted_at' => $base->addDays($i),
                'metrics' => ['views' => 1000, 'likes' => 50, 'comments' => 5, 'shares' => 0],
            ]);
        }

        $winnerPost = Post::factory()->forAccount($account)->create([
            'platform' => Platform::TikTok,
            'url' => 'https://www.tiktok.com/@demo/video/6718335390845095173',
            'media_url' => 'https://cdn.example.com/winner.mp4',
            'posted_at' => $base->addDays(20),
            'metrics' => ['views' => 5000, 'likes' => 400, 'comments' => 40, 'shares' => 10],
        ]);
        PostAnalysis::factory()->for($winnerPost)->create([
            'status' => AnalysisStatus::Completed,
            'hook' => 'Strong opening line here',
            'concept' => 'Pattern interrupt with proof',
            'topics' => ['social proof with receipts', 'contrast framing'],
        ]);

        $loserPost = Post::factory()->forAccount($account)->create([
            'posted_at' => $base->addDays(21),
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
    }

    public function test_winner_rules_can_be_updated(): void
    {
        Queue::fake([ScoreWinnersJob::class]);

        $user = User::factory()->create();
        BrandProfile::factory()->for($user)->create();

        WinnerRule::factory()->for($user)->create([
            'preset' => 'balanced',
            ...(array) config('snitch.winners.presets.balanced'),
        ]);

        $this->actingAs($user)
            ->put(route('winners.rules.update'), [
                'preset' => 'strict',
                'min_multiplier' => 3,
                'min_views' => 0,
                'min_likes' => 0,
                'min_engagement_rate' => 0,
                'recency_days' => 30,
                'advanced' => [
                    'require_hook' => true,
                    'require_sfx' => false,
                    'min_score' => 0,
                ],
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('winner_rules', [
            'user_id' => $user->id,
            'preset' => 'strict',
            'min_multiplier' => 3,
        ]);

        Queue::assertPushed(ScoreWinnersJob::class, fn (ScoreWinnersJob $job) => $job->userId === $user->id);
    }

    public function test_rescore_queues_job_and_exposes_active_run(): void
    {
        Queue::fake([ScoreWinnersJob::class]);

        $user = User::factory()->create();
        BrandProfile::factory()->for($user)->create();
        WinnerRule::factory()->for($user)->create();

        $this->actingAs($user)
            ->from(route('dashboard'))
            ->post(route('winners.rescore'))
            ->assertRedirect(route('dashboard'));

        Queue::assertPushed(ScoreWinnersJob::class, fn (ScoreWinnersJob $job) => $job->userId === $user->id);

        $run = ScoreWinnersJob::activeRunFor($user->id);
        $this->assertNotNull($run);
        $this->assertSame('pending', $run['status']);

        $this->actingAs($user)
            ->getJson(route('winners.rescore.status', $run['id']))
            ->assertOk()
            ->assertJson([
                'status' => 'pending',
                'error' => null,
                'winner_count' => null,
            ]);
    }

    public function test_score_winners_job_marks_run_completed(): void
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
            'preset' => 'balanced',
            ...(array) config('snitch.winners.presets.balanced'),
            'recency_days' => 90,
            'advanced' => ['require_hook' => true, 'require_sfx' => false, 'min_score' => 0],
        ]);

        $base = CarbonImmutable::now()->subDays(40);

        for ($i = 0; $i < 12; $i++) {
            Post::factory()->forAccount($account)->create([
                'posted_at' => $base->addDays($i),
                'metrics' => ['views' => 1000, 'likes' => 50, 'comments' => 5, 'shares' => 0],
            ]);
        }

        $post = Post::factory()->forAccount($account)->create([
            'posted_at' => $base->addDays(20),
            'metrics' => ['views' => 5000, 'likes' => 400, 'comments' => 40, 'shares' => 10],
        ]);
        PostAnalysis::factory()->for($post)->create([
            'status' => AnalysisStatus::Completed,
            'hook' => 'Strong opening line here',
        ]);

        $runId = (string) Str::uuid();
        Cache::put(ScoreWinnersJob::cacheKeyFor($user->id, $runId), [
            'status' => 'pending',
            'error' => null,
            'winner_count' => null,
        ], now()->addHour());
        Cache::put(ScoreWinnersJob::activeCacheKeyFor($user->id), $runId, now()->addHour());

        (new ScoreWinnersJob($user->id, $runId))->handle(app(WinnerScorer::class));

        $this->assertNull(ScoreWinnersJob::activeRunFor($user->id));
        $this->assertSame(
            [
                'status' => 'completed',
                'error' => null,
                'winner_count' => 1,
            ],
            ScoreWinnersJob::statusFor($user->id, $runId),
        );
    }
}
