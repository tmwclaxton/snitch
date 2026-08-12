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
use App\Services\Billing\UsageBillingService;
use App\Services\Billing\VendorUsageCharger;
use App\Services\Scraping\YoutubeMediaHydrator;
use App\Services\Winners\WinnerScorer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Mockery;
use RuntimeException;
use Tests\Concerns\WithPlatformBilling;
use Tests\TestCase;

class AnalyzePostJobUnavailableTest extends TestCase
{
    use RefreshDatabase;
    use WithPlatformBilling;

    public function test_marks_post_unavailable_when_media_returns_404(): void
    {
        Http::fake([
            'https://cdn.example.com/gone.mp4' => Http::response(null, 404),
        ]);

        $user = User::factory()->create();
        $this->enablePlatformBilling($user);
        $account = TrackedAccount::factory()->for($user)->create();
        $post = Post::factory()->forAccount($account)->create([
            'type' => PostType::Reel,
            'media_url' => 'https://cdn.example.com/gone.mp4',
            'posted_at' => now()->subDay(),
        ]);

        $analysis = Mockery::mock(VideoAnalysisService::class);
        $analysis->shouldNotReceive('analyzePost');
        $this->app->instance(VideoAnalysisService::class, $analysis);

        $this->runAnalyzeJob($post->id);

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

        $this->runAnalyzeJob($post->id);

        $this->assertSame(MediaAvailability::Unavailable, $post->fresh()->media_availability);
    }

    public function test_fails_analysis_for_youtube_page_media_when_hydrate_unavailable(): void
    {
        $user = User::factory()->create();
        $this->enablePlatformBilling($user);
        $account = TrackedAccount::factory()->for($user)->create([
            'platform' => Platform::Youtube,
        ]);
        $post = Post::factory()->forAccount($account)->create([
            'platform' => Platform::Youtube,
            'type' => PostType::Reel,
            'external_id' => 'abc123',
            'url' => 'https://www.youtube.com/shorts/abc123',
            'media_url' => 'https://www.youtube.com/shorts/abc123',
            'posted_at' => now()->subDay(),
            'media_availability' => MediaAvailability::Available,
        ]);

        $analysis = Mockery::mock(VideoAnalysisService::class);
        $analysis->shouldNotReceive('analyzePost');
        $this->app->instance(VideoAnalysisService::class, $analysis);

        $hydrator = Mockery::mock(YoutubeMediaHydrator::class);
        $hydrator->shouldReceive('needsHydration')->once()->andReturn(true);
        $hydrator->shouldReceive('resolveDownloadUrl')->once()->andReturn(null);
        $this->app->instance(YoutubeMediaHydrator::class, $hydrator);

        $this->runAnalyzeJob($post->id);

        $post->refresh();
        $this->assertSame(MediaAvailability::Available, $post->media_availability);
        $this->assertSame(AnalysisStatus::Failed, $post->analysis?->status);
        $this->assertStringContainsString('downloadable MP4', (string) $post->analysis?->error_message);
    }

    public function test_hydrates_youtube_page_media_then_analyzes(): void
    {
        Http::fake([
            'https://www.snitchsocial.net/storage/*' => Http::response(null, 200),
        ]);

        $user = User::factory()->create();
        $this->enablePlatformBilling($user);
        $account = TrackedAccount::factory()->for($user)->create([
            'platform' => Platform::Youtube,
        ]);
        $post = Post::factory()->forAccount($account)->create([
            'platform' => Platform::Youtube,
            'type' => PostType::Reel,
            'external_id' => 'abc123',
            'url' => 'https://www.youtube.com/shorts/abc123',
            'media_url' => 'https://www.youtube.com/shorts/abc123',
            'posted_at' => now()->subDay(),
            'media_availability' => MediaAvailability::Available,
        ]);

        $analysisRow = $post->analysis()->create([
            'status' => AnalysisStatus::Pending,
        ]);

        $analysis = Mockery::mock(VideoAnalysisService::class);
        $analysis->shouldReceive('analyzePost')
            ->once()
            ->andReturn([
                'analysis' => $analysisRow->fill([
                    'status' => AnalysisStatus::Completed,
                    'hook' => 'Hook',
                    'cta' => 'No explicit CTA',
                ]),
                'prompt_tokens' => 100,
                'completion_tokens' => 50,
            ]);
        $this->app->instance(VideoAnalysisService::class, $analysis);

        $hydrator = Mockery::mock(YoutubeMediaHydrator::class);
        $hydrator->shouldReceive('needsHydration')->once()->andReturn(true);
        $hydrator->shouldReceive('resolveDownloadUrl')
            ->once()
            ->andReturn('https://www.snitchsocial.net/storage/youtube-media/abc123.mp4');
        $this->app->instance(YoutubeMediaHydrator::class, $hydrator);

        $scorer = Mockery::mock(WinnerScorer::class);
        $scorer->shouldReceive('scoreAndPersist')->once();

        (new AnalyzePostJob($post->id))->handle(
            app(VideoAnalysisService::class),
            $scorer,
            app(VendorUsageCharger::class),
            app(UsageBillingService::class),
            app(YoutubeMediaHydrator::class),
        );

        $this->assertSame(
            'https://www.snitchsocial.net/storage/youtube-media/abc123.mp4',
            $post->fresh()->media_url,
        );
    }

    public function test_checklist_failures_do_not_rethrow_for_queue_retries(): void
    {
        Http::fake([
            'https://cdn.example.com/clip.mp4' => Http::response(null, 200),
        ]);

        $user = User::factory()->create();
        $this->enablePlatformBilling($user);
        $account = TrackedAccount::factory()->for($user)->create();
        $post = Post::factory()->forAccount($account)->create([
            'type' => PostType::Reel,
            'media_url' => 'https://cdn.example.com/clip.mp4',
            'posted_at' => now()->subDay(),
            'media_availability' => MediaAvailability::Available,
        ]);

        $analysis = Mockery::mock(VideoAnalysisService::class);
        $analysis->shouldReceive('analyzePost')
            ->once()
            ->andThrow(new RuntimeException('Analysis failed checklist: cta missing'));
        $this->app->instance(VideoAnalysisService::class, $analysis);

        $scorer = Mockery::mock(WinnerScorer::class);
        $scorer->shouldNotReceive('scoreAndPersist');

        $this->runAnalyzeJob($post->id, $scorer);

        $this->assertSame(MediaAvailability::Available, $post->fresh()->media_availability);
    }

    public function test_nanogpt_invalid_request_400_does_not_rethrow_for_queue_retries(): void
    {
        Http::fake([
            'https://cdn.example.com/clip.mp4' => Http::response(null, 200),
        ]);

        $user = User::factory()->create();
        $this->enablePlatformBilling($user);
        $account = TrackedAccount::factory()->for($user)->create();
        $post = Post::factory()->forAccount($account)->create([
            'type' => PostType::Reel,
            'media_url' => 'https://cdn.example.com/clip.mp4',
            'posted_at' => now()->subDay(),
            'media_availability' => MediaAvailability::Available,
        ]);

        $analysis = Mockery::mock(VideoAnalysisService::class);
        $analysis->shouldReceive('analyzePost')
            ->once()
            ->andThrow(new RuntimeException(
                'HTTP request returned status code 400:'."\n".
                '{"error":{"message":"Invalid request parameters. Please check your input and try again.","type":"invalid_request_error"}}',
            ));
        $this->app->instance(VideoAnalysisService::class, $analysis);

        $scorer = Mockery::mock(WinnerScorer::class);
        $scorer->shouldNotReceive('scoreAndPersist');

        $this->runAnalyzeJob($post->id, $scorer);

        $this->assertSame(MediaAvailability::Available, $post->fresh()->media_availability);
    }

    public function test_invalid_video_analysis_json_does_not_rethrow_for_queue_retries(): void
    {
        Http::fake([
            'https://cdn.example.com/clip.mp4' => Http::response(null, 200),
        ]);

        $user = User::factory()->create();
        $this->enablePlatformBilling($user);
        $account = TrackedAccount::factory()->for($user)->create();
        $post = Post::factory()->forAccount($account)->create([
            'type' => PostType::Reel,
            'media_url' => 'https://cdn.example.com/clip.mp4',
            'posted_at' => now()->subDay(),
            'media_availability' => MediaAvailability::Available,
        ]);

        $analysis = Mockery::mock(VideoAnalysisService::class);
        $analysis->shouldReceive('analyzePost')
            ->once()
            ->andThrow(new RuntimeException('Video analysis did not return valid JSON.'));
        $this->app->instance(VideoAnalysisService::class, $analysis);

        $scorer = Mockery::mock(WinnerScorer::class);
        $scorer->shouldNotReceive('scoreAndPersist');

        $this->runAnalyzeJob($post->id, $scorer);

        $this->assertSame(MediaAvailability::Available, $post->fresh()->media_availability);
    }

    private function runAnalyzeJob(int $postId, ?WinnerScorer $scorer = null): void
    {
        (new AnalyzePostJob($postId))->handle(
            app(VideoAnalysisService::class),
            $scorer ?? app(WinnerScorer::class),
            app(VendorUsageCharger::class),
            app(UsageBillingService::class),
            app(YoutubeMediaHydrator::class),
        );
    }
}
