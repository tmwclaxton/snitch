<?php

namespace Tests\Feature\Pipeline;

use App\Enums\AnalysisStatus;
use App\Enums\MediaAvailability;
use App\Enums\Platform;
use App\Enums\PostType;
use App\Jobs\AnalyzePostJob;
use App\Models\Post;
use App\Models\TrackedAccount;
use App\Models\User;
use App\Services\Analysis\VideoAnalysisService;
use App\Services\Winners\WinnerScorer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

class AnalyzePostJobUnavailableTest extends TestCase
{
    use RefreshDatabase;

    public function test_marks_post_unavailable_when_media_returns_404(): void
    {
        Http::fake([
            'https://cdn.example.com/gone.mp4' => Http::response(null, 404),
        ]);

        $user = User::factory()->create();
        $account = TrackedAccount::factory()->for($user)->create();
        $post = Post::factory()->forAccount($account)->create([
            'type' => PostType::Reel,
            'media_url' => 'https://cdn.example.com/gone.mp4',
            'posted_at' => now()->subDay(),
        ]);

        $analysis = Mockery::mock(VideoAnalysisService::class);
        $analysis->shouldNotReceive('analyzePost');
        $this->app->instance(VideoAnalysisService::class, $analysis);

        (new AnalyzePostJob($post->id))->handle(
            app(VideoAnalysisService::class),
            app(WinnerScorer::class),
        );

        $post->refresh();
        $this->assertSame(MediaAvailability::Unavailable, $post->media_availability);
        $this->assertNotNull($post->unavailable_at);
        $this->assertSame(AnalysisStatus::Unavailable, $post->analysis?->status);
    }

    public function test_skips_already_unavailable_posts(): void
    {
        $user = User::factory()->create();
        $account = TrackedAccount::factory()->for($user)->create();
        $post = Post::factory()->forAccount($account)->create([
            'type' => PostType::Reel,
            'media_url' => 'https://cdn.example.com/gone.mp4',
            'media_availability' => MediaAvailability::Unavailable,
            'unavailable_at' => now(),
            'unavailable_reason' => 'gone',
            'posted_at' => now()->subDay(),
        ]);

        $analysis = Mockery::mock(VideoAnalysisService::class);
        $analysis->shouldNotReceive('analyzePost');
        $this->app->instance(VideoAnalysisService::class, $analysis);

        (new AnalyzePostJob($post->id))->handle(
            app(VideoAnalysisService::class),
            app(WinnerScorer::class),
        );

        $this->assertSame(MediaAvailability::Unavailable, $post->fresh()->media_availability);
    }

    public function test_fails_analysis_for_youtube_page_media_without_calling_nanogpt(): void
    {
        $user = User::factory()->create();
        $account = TrackedAccount::factory()->for($user)->create([
            'platform' => Platform::Youtube,
        ]);
        $post = Post::factory()->forAccount($account)->create([
            'platform' => Platform::Youtube,
            'type' => PostType::Reel,
            'media_url' => 'https://www.youtube.com/shorts/abc123',
            'posted_at' => now()->subDay(),
            'media_availability' => MediaAvailability::Available,
        ]);

        $analysis = Mockery::mock(VideoAnalysisService::class);
        $analysis->shouldNotReceive('analyzePost');
        $this->app->instance(VideoAnalysisService::class, $analysis);

        (new AnalyzePostJob($post->id))->handle(
            app(VideoAnalysisService::class),
            app(WinnerScorer::class),
        );

        $post->refresh();
        $this->assertSame(MediaAvailability::Available, $post->media_availability);
        $this->assertSame(AnalysisStatus::Failed, $post->analysis?->status);
        $this->assertStringContainsString('downloadable MP4', (string) $post->analysis?->error_message);
    }
}
