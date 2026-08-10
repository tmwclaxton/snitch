<?php

namespace Tests\Feature\Pipeline;

use App\Enums\AnalysisStatus;
use App\Enums\PostType;
use App\Jobs\AnalyzePostJob;
use App\Jobs\EmbedPostAnalysisJob;
use App\Models\Post;
use App\Models\PostAnalysis;
use App\Models\TrackedAccount;
use App\Models\User;
use App\Services\Analysis\VideoAnalysisService;
use App\Services\Billing\UsageBillingService;
use App\Services\Billing\VendorUsageCharger;
use App\Services\Scraping\YoutubeMediaHydrator;
use App\Services\Winners\WinnerScorer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\WithPlatformBilling;
use Tests\TestCase;

class EmbedPostAnalysisJobDispatchTest extends TestCase
{
    use RefreshDatabase;
    use WithPlatformBilling;

    #[Test]
    public function successful_analysis_dispatches_embed_job(): void
    {
        Http::fake([
            'https://cdn.example.com/ok.mp4' => Http::response('', 200),
        ]);

        Queue::fake([EmbedPostAnalysisJob::class]);

        $user = User::factory()->create();
        $this->enablePlatformBilling($user);
        $account = TrackedAccount::factory()->for($user)->create();
        $post = Post::factory()->forAccount($account)->create([
            'type' => PostType::Reel,
            'media_url' => 'https://cdn.example.com/ok.mp4',
            'posted_at' => now()->subDay(),
        ]);

        $analysis = PostAnalysis::factory()->for($post)->create([
            'status' => AnalysisStatus::Completed,
        ]);

        $video = Mockery::mock(VideoAnalysisService::class);
        $video->shouldReceive('analyzePost')->once()->andReturn([
            'analysis' => $analysis,
            'prompt_tokens' => 100,
            'completion_tokens' => 50,
        ]);
        $this->app->instance(VideoAnalysisService::class, $video);

        $scorer = Mockery::mock(WinnerScorer::class);
        $scorer->shouldReceive('scoreAndPersist')->once();
        $this->app->instance(WinnerScorer::class, $scorer);

        (new AnalyzePostJob($post->id))->handle(
            app(VideoAnalysisService::class),
            app(WinnerScorer::class),
            app(VendorUsageCharger::class),
            app(UsageBillingService::class),
            app(YoutubeMediaHydrator::class),
        );

        Queue::assertPushed(
            EmbedPostAnalysisJob::class,
            fn (EmbedPostAnalysisJob $job): bool => $job->postAnalysisId === $analysis->id,
        );
    }
}
