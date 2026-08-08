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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
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
        $account = TrackedAccount::factory()->for($user)->create([
            'platform' => Platform::TikTok,
        ]);

        WinnerRule::factory()->for($user)->create([
            'preset' => 'aggressive',
            ...(array) config('snitch.winners.presets.aggressive'),
            'advanced' => ['require_hook' => true, 'require_sfx' => false, 'min_score' => 5],
        ]);

        $winnerPost = Post::factory()->forAccount($account)->create([
            'platform' => Platform::TikTok,
            'url' => 'https://www.tiktok.com/@demo/video/6718335390845095173',
            'media_url' => 'https://cdn.example.com/winner.mp4',
            'posted_at' => now()->subDay(),
            'metrics' => ['views' => 5000, 'likes' => 400, 'comments' => 40, 'shares' => 10],
        ]);
        PostAnalysis::factory()->for($winnerPost)->create([
            'status' => AnalysisStatus::Completed,
            'hook' => 'Strong opening line here',
            'concept' => 'Pattern interrupt with proof',
            'topics' => ['proof', 'contrast'],
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
                ->where('winners.0.post.analysis.hook', 'Strong opening line here')
                ->where('winners.0.post.analysis.concept', 'Pattern interrupt with proof')
                ->where('winners.0.post.analysis.topics.0', 'proof')
                ->where('winners.0.post.embed.provider', 'tiktok')
                ->where(
                    'winners.0.post.embed.src',
                    'https://www.tiktok.com/player/v1/6718335390845095173?music_info=0&description=0&autoplay=0',
                )
                ->where(
                    'winners.0.how_to_copy_html',
                    fn (?string $html): bool => is_string($html)
                        && $html !== ''
                        && str_contains($html, '<'),
                )
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
        Queue::fake([ScoreWinnersJob::class]);

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

        Queue::assertPushed(ScoreWinnersJob::class, fn (ScoreWinnersJob $job) => $job->userId === $user->id);
    }

    public function test_rescore_queues_job_and_exposes_active_run(): void
    {
        Queue::fake([ScoreWinnersJob::class]);

        $user = User::factory()->create();
        BrandProfile::factory()->for($user)->create();
        WinnerRule::factory()->for($user)->create();

        $this->actingAs($user)
            ->from(route('winners.index'))
            ->post(route('winners.rescore'))
            ->assertRedirect(route('winners.index'));

        Queue::assertPushed(ScoreWinnersJob::class, fn (ScoreWinnersJob $job) => $job->userId === $user->id);

        $run = ScoreWinnersJob::activeRunFor($user->id);
        $this->assertNotNull($run);
        $this->assertSame('pending', $run['status']);

        $this->actingAs($user)
            ->get(route('winners.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('winners/Index')
                ->where('rescoreRun.id', $run['id'])
                ->where('rescoreRun.status', 'pending')
            );

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
