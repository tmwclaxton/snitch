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
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\Concerns\WithPlatformBilling;
use Tests\TestCase;

class AnalyzePostJobLocalMediaTest extends TestCase
{
    use RefreshDatabase;
    use WithPlatformBilling;

    public function test_does_not_mark_unavailable_when_public_disk_media_exists_despite_http_403(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('youtube-media/abc123.mp4', 'fake-mp4-bytes');

        Http::fake([
            'http://localhost*' => Http::response('Forbidden', 403),
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
            'media_url' => 'http://localhost:8000/storage/youtube-media/abc123.mp4',
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

        $scorer = Mockery::mock(WinnerScorer::class);
        $scorer->shouldReceive('scoreAndPersist')->once();

        (new AnalyzePostJob($post->id))->handle(
            app(VideoAnalysisService::class),
            $scorer,
            app(VendorUsageCharger::class),
            app(UsageBillingService::class),
            app(YoutubeMediaHydrator::class),
        );

        $post->refresh();
        $this->assertSame(MediaAvailability::Available, $post->media_availability);
        $this->assertNull($post->unavailable_at);
    }

    public function test_marks_unavailable_when_public_disk_media_file_is_missing(): void
    {
        Storage::fake('public');

        Http::fake([
            'http://localhost*' => Http::response('Forbidden', 403),
        ]);

        $user = User::factory()->create();
        $this->enablePlatformBilling($user);
        $account = TrackedAccount::factory()->for($user)->create([
            'platform' => Platform::Youtube,
        ]);
        $post = Post::factory()->forAccount($account)->create([
            'platform' => Platform::Youtube,
            'type' => PostType::Reel,
            'external_id' => 'missing1',
            'url' => 'https://www.youtube.com/shorts/missing1',
            'media_url' => 'http://localhost:8000/storage/youtube-media/missing1.mp4',
            'posted_at' => now()->subDay(),
            'media_availability' => MediaAvailability::Available,
        ]);

        $analysis = Mockery::mock(VideoAnalysisService::class);
        $analysis->shouldNotReceive('analyzePost');
        $this->app->instance(VideoAnalysisService::class, $analysis);

        (new AnalyzePostJob($post->id))->handle(
            app(VideoAnalysisService::class),
            app(WinnerScorer::class),
            app(VendorUsageCharger::class),
            app(UsageBillingService::class),
            app(YoutubeMediaHydrator::class),
        );

        $post->refresh();
        $this->assertSame(MediaAvailability::Unavailable, $post->media_availability);
        $this->assertSame(AnalysisStatus::Unavailable, $post->analysis?->status);
    }
}
