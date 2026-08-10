<?php

namespace Tests\Feature\Pipeline;

use App\Enums\AnalysisStatus;
use App\Enums\Platform;
use App\Enums\PostType;
use App\Jobs\AnalyzePostJob;
use App\Jobs\ScoreWinnersJob;
use App\Jobs\SyncTrackedAccountJob;
use App\Models\Post;
use App\Models\PostAnalysis;
use App\Models\TrackedAccount;
use App\Models\User;
use App\Services\Apify\ApifyClient;
use App\Services\Apify\PlatformAdapterManager;
use App\Services\Billing\VendorUsageCharger;
use App\Services\SnitchAnalyticsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\Concerns\WithPlatformBilling;
use Tests\TestCase;

class SyncTrackedAccountJobTest extends TestCase
{
    use RefreshDatabase;
    use WithPlatformBilling;

    public function test_sync_imports_recent_reels_skips_old_and_enqueues_analysis(): void
    {
        Queue::fake([AnalyzePostJob::class, ScoreWinnersJob::class]);

        $user = User::factory()->create();
        $this->enablePlatformBilling($user);
        $account = TrackedAccount::factory()->for($user)->create([
            'platform' => Platform::Facebook,
            'handle' => 'rivalbakery',
        ]);

        $client = Mockery::mock(ApifyClient::class);
        $client->shouldReceive('pullRunCosts')->andReturn([]);
        $client->shouldReceive('runActor')->andReturn([
            [
                'pageName' => 'Rival Bakery',
                'pageId' => 'page_1',
                'pageProfilePictureUrl' => null,
                'postId' => 'recent_reel',
                'url' => 'https://facebook.com/rivalbakery/videos/1',
                'text' => 'Fresh reel',
                'time' => now()->subDays(2)->toIso8601String(),
                'type' => 'video',
                'videoUrl' => 'https://cdn.example.com/recent.mp4',
                'likes' => 10,
                'comments' => 1,
                'shares' => 0,
                'viewsCount' => 100,
            ],
            [
                'pageName' => 'Rival Bakery',
                'pageId' => 'page_1',
                'postId' => 'old_reel',
                'url' => 'https://facebook.com/rivalbakery/videos/2',
                'text' => 'Old reel',
                'time' => now()->subDays(60)->toIso8601String(),
                'type' => 'video',
                'videoUrl' => 'https://cdn.example.com/old.mp4',
                'likes' => 10,
                'comments' => 1,
                'shares' => 0,
                'viewsCount' => 100,
            ],
            [
                'pageName' => 'Rival Bakery',
                'pageId' => 'page_1',
                'postId' => 'image_skip',
                'url' => 'https://facebook.com/rivalbakery/posts/3',
                'text' => 'Still',
                'time' => now()->subDays(1)->toIso8601String(),
                'type' => 'photo',
                'image' => 'https://cdn.example.com/still.jpg',
                'likes' => 10,
            ],
        ]);
        $this->app->instance(ApifyClient::class, $client);

        config([
            'snitch.sync.recency_days' => 30,
            'snitch.sync.posts_limit' => 12,
        ]);

        (new SyncTrackedAccountJob($account->id))->handle(app(PlatformAdapterManager::class), app(SnitchAnalyticsService::class), app(VendorUsageCharger::class));

        $account->refresh();
        $this->assertSame('success', $account->last_sync_status);
        $this->assertNull($account->last_sync_error);
        $this->assertNotNull($account->last_synced_at);

        $this->assertSame(1, Post::query()->count());
        $post = Post::query()->first();
        $this->assertSame('recent_reel', $post->external_id);
        $this->assertSame('https://cdn.example.com/recent.mp4', $post->media_url);
        $this->assertContains($post->type, PostType::analyzable());

        Queue::assertPushed(AnalyzePostJob::class, fn (AnalyzePostJob $job) => $job->postId === $post->id);
        Queue::assertPushed(ScoreWinnersJob::class, fn (ScoreWinnersJob $job) => $job->userId === $user->id);
    }

    public function test_sync_retries_failed_analysis_and_records_sync_failure(): void
    {
        Queue::fake([AnalyzePostJob::class, ScoreWinnersJob::class]);

        $user = User::factory()->create();
        $this->enablePlatformBilling($user);
        $account = TrackedAccount::factory()->for($user)->create([
            'platform' => Platform::Facebook,
            'handle' => 'rivalbakery',
        ]);

        $existing = Post::factory()->forAccount($account)->create([
            'external_id' => 'recent_reel',
            'type' => PostType::Video,
            'media_url' => 'https://cdn.example.com/recent.mp4',
            'posted_at' => now()->subDays(1),
        ]);
        PostAnalysis::factory()->for($existing)->create([
            'status' => AnalysisStatus::Failed,
            'error_message' => 'previous fail',
        ]);

        $client = Mockery::mock(ApifyClient::class);
        $client->shouldReceive('pullRunCosts')->andReturn([]);
        $client->shouldReceive('runActor')->andReturn([
            [
                'pageName' => 'Rival Bakery',
                'pageId' => 'page_1',
                'postId' => 'recent_reel',
                'url' => 'https://facebook.com/rivalbakery/videos/1',
                'text' => 'Fresh reel',
                'time' => now()->subDays(1)->toIso8601String(),
                'type' => 'video',
                'videoUrl' => 'https://cdn.example.com/recent.mp4',
                'likes' => 10,
            ],
        ]);
        $this->app->instance(ApifyClient::class, $client);

        (new SyncTrackedAccountJob($account->id))->handle(app(PlatformAdapterManager::class), app(SnitchAnalyticsService::class), app(VendorUsageCharger::class));

        Queue::assertPushed(AnalyzePostJob::class, fn (AnalyzePostJob $job) => $job->postId === $existing->id);

        $clientFail = Mockery::mock(ApifyClient::class);
        $clientFail->shouldReceive('runActor')->andThrow(new \RuntimeException('Apify down'));
        $this->app->instance(ApifyClient::class, $clientFail);

        try {
            // Force bypasses the weekly min-interval skip after a successful sync.
            (new SyncTrackedAccountJob($account->id, force: true))->handle(app(PlatformAdapterManager::class), app(SnitchAnalyticsService::class), app(VendorUsageCharger::class));
            $this->fail('Expected sync to throw');
        } catch (\RuntimeException $e) {
            $this->assertSame('Apify down', $e->getMessage());
        }

        $account->refresh();
        $this->assertSame('failed', $account->last_sync_status);
        $this->assertSame('Apify down', $account->last_sync_error);
    }

    public function test_sync_failure_redacts_apify_token_from_user_visible_error(): void
    {
        Queue::fake([AnalyzePostJob::class, ScoreWinnersJob::class]);

        $user = User::factory()->create();
        $this->enablePlatformBilling($user);
        $account = TrackedAccount::factory()->for($user)->create([
            'platform' => Platform::Facebook,
            'handle' => 'rivalbakery',
        ]);

        $clientFail = Mockery::mock(ApifyClient::class);
        $clientFail->shouldReceive('runActor')->andThrow(new \RuntimeException(
            'cURL error 28: timed out for https://api.apify.com/v2/acts/x/run-sync-get-dataset-items?token=SECRET_APIFY_TOKEN_VALUE',
        ));
        $this->app->instance(ApifyClient::class, $clientFail);

        try {
            (new SyncTrackedAccountJob($account->id, force: true))->handle(
                app(PlatformAdapterManager::class),
                app(SnitchAnalyticsService::class),
                app(VendorUsageCharger::class),
            );
            $this->fail('Expected sync to throw');
        } catch (\RuntimeException) {
            // expected
        }

        $account->refresh();
        $this->assertSame('failed', $account->last_sync_status);
        $this->assertStringContainsString('token=[redacted]', (string) $account->last_sync_error);
        $this->assertStringNotContainsString('SECRET_APIFY_TOKEN_VALUE', (string) $account->last_sync_error);
    }

    public function test_sync_skips_when_successfully_synced_within_min_interval(): void
    {
        Queue::fake([AnalyzePostJob::class, ScoreWinnersJob::class]);

        config(['snitch.sync.min_interval_days' => 7]);

        $user = User::factory()->create();
        $this->enablePlatformBilling($user);
        $account = TrackedAccount::factory()->for($user)->create([
            'platform' => Platform::Facebook,
            'handle' => 'rivalbakery',
            'last_synced_at' => now()->subDays(2),
            'last_sync_status' => 'success',
        ]);

        $client = Mockery::mock(ApifyClient::class);
        $client->shouldReceive('pullRunCosts')->andReturn([]);
        $client->shouldNotReceive('runActor');
        $this->app->instance(ApifyClient::class, $client);

        (new SyncTrackedAccountJob($account->id))->handle(app(PlatformAdapterManager::class), app(SnitchAnalyticsService::class), app(VendorUsageCharger::class));

        Queue::assertNothingPushed();
        $this->assertSame(0, Post::query()->count());
        $this->assertSame('success', $account->fresh()?->last_sync_status);
    }

    public function test_sync_runs_when_already_marked_running_even_if_recently_synced(): void
    {
        Queue::fake([AnalyzePostJob::class, ScoreWinnersJob::class]);

        config([
            'snitch.sync.min_interval_days' => 7,
            'snitch.sync.recency_days' => 30,
            'snitch.sync.posts_limit' => 12,
        ]);

        $user = User::factory()->create();
        $this->enablePlatformBilling($user);
        $account = TrackedAccount::factory()->for($user)->create([
            'platform' => Platform::Facebook,
            'handle' => 'rivalbakery',
            'last_synced_at' => now()->subDay(),
            'last_sync_status' => 'running',
        ]);

        $client = Mockery::mock(ApifyClient::class);
        $client->shouldReceive('pullRunCosts')->andReturn([]);
        $client->shouldReceive('runActor')->andReturn([
            [
                'pageName' => 'Rival Bakery',
                'pageId' => 'page_1',
                'postId' => 'queued_reel',
                'url' => 'https://facebook.com/rivalbakery/videos/11',
                'text' => 'Queued',
                'time' => now()->subDay()->toIso8601String(),
                'type' => 'video',
                'videoUrl' => 'https://cdn.example.com/queued.mp4',
                'likes' => 2,
            ],
        ]);
        $this->app->instance(ApifyClient::class, $client);

        (new SyncTrackedAccountJob($account->id))->handle(app(PlatformAdapterManager::class), app(SnitchAnalyticsService::class), app(VendorUsageCharger::class));

        $account->refresh();
        $this->assertSame('success', $account->last_sync_status);
        $this->assertSame(1, Post::query()->count());
    }

    public function test_force_sync_runs_even_when_recently_synced(): void
    {
        Queue::fake([AnalyzePostJob::class, ScoreWinnersJob::class]);

        config([
            'snitch.sync.min_interval_days' => 7,
            'snitch.sync.recency_days' => 30,
            'snitch.sync.posts_limit' => 12,
        ]);

        $user = User::factory()->create();
        $this->enablePlatformBilling($user);
        $account = TrackedAccount::factory()->for($user)->create([
            'platform' => Platform::Facebook,
            'handle' => 'rivalbakery',
            'last_synced_at' => now()->subDay(),
            'last_sync_status' => 'success',
        ]);

        $client = Mockery::mock(ApifyClient::class);
        $client->shouldReceive('pullRunCosts')->andReturn([]);
        $client->shouldReceive('runActor')->andReturn([
            [
                'pageName' => 'Rival Bakery',
                'pageId' => 'page_1',
                'postId' => 'forced_reel',
                'url' => 'https://facebook.com/rivalbakery/videos/9',
                'text' => 'Forced',
                'time' => now()->subDay()->toIso8601String(),
                'type' => 'video',
                'videoUrl' => 'https://cdn.example.com/forced.mp4',
                'likes' => 3,
            ],
        ]);
        $this->app->instance(ApifyClient::class, $client);

        (new SyncTrackedAccountJob($account->id, force: true))->handle(app(PlatformAdapterManager::class), app(SnitchAnalyticsService::class), app(VendorUsageCharger::class));

        $this->assertSame(1, Post::query()->count());
        $account->refresh();
        $this->assertSame('success', $account->last_sync_status);
    }

    public function test_sync_skips_resolve_when_profile_fields_present(): void
    {
        Queue::fake([AnalyzePostJob::class, ScoreWinnersJob::class]);

        $user = User::factory()->create();
        $this->enablePlatformBilling($user);
        $account = TrackedAccount::factory()->for($user)->create([
            'platform' => Platform::Facebook,
            'handle' => 'rivalbakery',
            'external_id' => 'page_1',
            'url' => 'https://facebook.com/rivalbakery',
            'display_name' => 'Rival Bakery',
            'last_synced_at' => null,
            'last_sync_status' => null,
        ]);

        $client = Mockery::mock(ApifyClient::class);
        $client->shouldReceive('pullRunCosts')->andReturn([]);
        $client->shouldReceive('runActor')->once()->andReturn([
            [
                'pageName' => 'Rival Bakery',
                'pageId' => 'page_1',
                'postId' => 'only_list',
                'url' => 'https://facebook.com/rivalbakery/videos/22',
                'text' => 'List only',
                'time' => now()->subDay()->toIso8601String(),
                'type' => 'video',
                'videoUrl' => 'https://cdn.example.com/list-only.mp4',
                'likes' => 4,
            ],
        ]);
        $this->app->instance(ApifyClient::class, $client);

        config([
            'snitch.sync.recency_days' => 30,
            'snitch.sync.posts_limit' => 12,
        ]);

        (new SyncTrackedAccountJob($account->id))->handle(app(PlatformAdapterManager::class), app(SnitchAnalyticsService::class), app(VendorUsageCharger::class));

        $this->assertSame(1, Post::query()->count());
        $this->assertSame('page_1', $account->fresh()?->external_id);
    }

    public function test_sync_does_not_update_metrics_for_known_posts(): void
    {
        Queue::fake([AnalyzePostJob::class, ScoreWinnersJob::class]);

        $user = User::factory()->create();
        $this->enablePlatformBilling($user);
        $account = TrackedAccount::factory()->for($user)->create([
            'platform' => Platform::Facebook,
            'handle' => 'rivalbakery',
            'external_id' => 'page_1',
            'url' => 'https://facebook.com/rivalbakery',
            'display_name' => 'Rival Bakery',
        ]);

        $existing = Post::factory()->forAccount($account)->create([
            'external_id' => 'known_reel',
            'type' => PostType::Video,
            'media_url' => 'https://cdn.example.com/known.mp4',
            'posted_at' => now()->subDays(1),
            'metrics' => ['views' => 10, 'likes' => 1, 'comments' => 0, 'shares' => 0],
        ]);
        PostAnalysis::factory()->for($existing)->create([
            'status' => AnalysisStatus::Completed,
        ]);

        $client = Mockery::mock(ApifyClient::class);
        $client->shouldReceive('pullRunCosts')->andReturn([]);
        $client->shouldReceive('runActor')->once()->andReturn([
            [
                'pageName' => 'Rival Bakery',
                'pageId' => 'page_1',
                'postId' => 'known_reel',
                'url' => 'https://facebook.com/rivalbakery/videos/1',
                'text' => 'Updated caption',
                'time' => now()->subDays(1)->toIso8601String(),
                'type' => 'video',
                'videoUrl' => 'https://cdn.example.com/known.mp4',
                'likes' => 999,
                'viewsCount' => 5000,
            ],
        ]);
        $this->app->instance(ApifyClient::class, $client);

        (new SyncTrackedAccountJob($account->id))->handle(app(PlatformAdapterManager::class), app(SnitchAnalyticsService::class), app(VendorUsageCharger::class));

        $existing->refresh();
        $this->assertSame(1, $existing->metrics['likes'] ?? null);
        $this->assertSame(10, $existing->metrics['views'] ?? null);
        $this->assertSame(1, Post::query()->count());
        Queue::assertNotPushed(AnalyzePostJob::class);
    }

    public function test_empty_apify_result_falls_back_to_tikhub_for_instagram(): void
    {
        Queue::fake([AnalyzePostJob::class, ScoreWinnersJob::class]);

        config([
            'snitch.apify.monthly_cap_usd' => 49,
            'snitch.tikhub.api_key' => 'tikhub-key',
            'snitch.tikhub.base_url' => 'https://api.tikhub.test',
            'snitch.sync.recency_days' => 30,
            'snitch.sync.posts_limit' => 3,
            'billing.vendors.tikhub.endpoints.instagram.floor_usd' => 0.002,
        ]);

        $user = User::factory()->create();
        $this->enablePlatformBilling($user);
        $account = TrackedAccount::factory()->for($user)->create([
            'platform' => Platform::Instagram,
            'handle' => 'ksi',
            'external_id' => 'ig_1',
            'url' => 'https://instagram.com/ksi',
            'display_name' => 'KSI',
            'last_synced_at' => now()->subHour(),
        ]);

        $client = Mockery::mock(ApifyClient::class);
        $client->shouldReceive('pullRunCosts')->andReturn([]);
        $client->shouldReceive('runActor')->andReturn([]);
        $this->app->instance(ApifyClient::class, $client);

        Http::preventStrayRequests();
        Http::fake([
            'https://api.tikhub.test/api/v1/instagram/v2/fetch_user_info*' => Http::response([
                'code' => 200,
                'data' => [
                    'user' => [
                        'username' => 'ksi',
                        'pk' => 'ig_1',
                        'full_name' => 'KSI',
                        'profile_pic_url' => 'https://cdn.example.com/avatar.jpg',
                    ],
                ],
            ]),
            'https://api.tikhub.test/api/v1/instagram/v2/fetch_user_reels*' => Http::response([
                'code' => 200,
                'data' => [
                    'items' => [
                        [
                            'code' => 'FALLBACK1',
                            'url' => 'https://www.instagram.com/reel/FALLBACK1/',
                            'taken_at' => now()->subDay()->timestamp,
                            'product_type' => 'clips',
                            'video_url' => 'https://cdn.example.com/fallback.mp4',
                            'caption' => ['text' => 'From TikHub'],
                            'like_count' => 10,
                            'comment_count' => 1,
                            'play_count' => 100,
                        ],
                    ],
                ],
            ]),
            'https://api.tikhub.test/api/v1/instagram/v2/fetch_user_posts*' => Http::response([
                'code' => 200,
                'data' => ['items' => []],
            ]),
        ]);

        (new SyncTrackedAccountJob($account->id, force: true))->handle(
            app(PlatformAdapterManager::class),
            app(SnitchAnalyticsService::class),
            app(VendorUsageCharger::class),
        );

        $account->refresh();
        $this->assertSame('success', $account->last_sync_status);
        $this->assertSame(1, Post::query()->count());
        $this->assertSame('FALLBACK1', Post::query()->value('external_id'));
        Queue::assertPushed(AnalyzePostJob::class);
    }

    public function test_force_sync_uses_full_recency_window_not_incremental_since(): void
    {
        Queue::fake([AnalyzePostJob::class, ScoreWinnersJob::class]);

        $user = User::factory()->create();
        $this->enablePlatformBilling($user);
        $account = TrackedAccount::factory()->for($user)->create([
            'platform' => Platform::Facebook,
            'handle' => 'rivalbakery',
            'external_id' => 'page_1',
            'url' => 'https://facebook.com/rivalbakery',
            'display_name' => 'Rival Bakery',
            // Poisoned by an earlier empty sync earlier today.
            'last_synced_at' => now()->subHour(),
        ]);

        $client = Mockery::mock(ApifyClient::class);
        $client->shouldReceive('pullRunCosts')->andReturn([]);
        $client->shouldReceive('runActor')->andReturn([
            [
                'pageName' => 'Rival Bakery',
                'pageId' => 'page_1',
                'postId' => 'older_reel',
                'url' => 'https://facebook.com/rivalbakery/videos/9',
                'text' => 'Still in window',
                'time' => now()->subDays(5)->toIso8601String(),
                'type' => 'video',
                'videoUrl' => 'https://cdn.example.com/older.mp4',
                'likes' => 10,
                'comments' => 1,
                'shares' => 0,
                'viewsCount' => 100,
            ],
        ]);
        $this->app->instance(ApifyClient::class, $client);

        config([
            'snitch.sync.recency_days' => 30,
            'snitch.sync.posts_limit' => 12,
        ]);

        (new SyncTrackedAccountJob($account->id, force: true))->handle(
            app(PlatformAdapterManager::class),
            app(SnitchAnalyticsService::class),
            app(VendorUsageCharger::class),
        );

        $this->assertSame(1, Post::query()->count());
        $this->assertSame('older_reel', Post::query()->value('external_id'));
    }

    public function test_tiktok_sync_hydrates_media_only_for_new_posts(): void
    {
        Queue::fake([AnalyzePostJob::class, ScoreWinnersJob::class]);

        $user = User::factory()->create();
        $this->enablePlatformBilling($user);
        $account = TrackedAccount::factory()->for($user)->create([
            'platform' => Platform::TikTok,
            'handle' => 'rivalbakery',
            'external_id' => 'tt_user_1',
            'url' => 'https://tiktok.com/@rivalbakery',
            'display_name' => 'Rival Bakery',
        ]);

        Post::factory()->forAccount($account)->create([
            'external_id' => 'known_tt',
            'type' => PostType::Reel,
            'url' => 'https://www.tiktok.com/@rivalbakery/video/111',
            'media_url' => 'https://cdn.tiktokcdn.com/known.mp4',
            'posted_at' => now()->subDays(2),
        ]);

        $client = Mockery::mock(ApifyClient::class);
        $client->shouldReceive('pullRunCosts')->andReturn([]);
        $client->shouldReceive('runActor')
            ->once()
            ->withArgs(function (string $actorId, array $input): bool {
                return $actorId === 'clockworks/tiktok-scraper'
                    && ($input['shouldDownloadVideos'] ?? null) === false
                    && isset($input['profiles']);
            })
            ->andReturn([
                [
                    'id' => 'known_tt',
                    'webVideoUrl' => 'https://www.tiktok.com/@rivalbakery/video/111',
                    'text' => 'Known',
                    'createTime' => now()->subDays(2)->timestamp,
                    'playCount' => 1,
                    'diggCount' => 1,
                    'commentCount' => 0,
                    'shareCount' => 0,
                ],
                [
                    'id' => 'new_tt',
                    'webVideoUrl' => 'https://www.tiktok.com/@rivalbakery/video/222',
                    'text' => 'New',
                    'createTime' => now()->subDay()->timestamp,
                    'playCount' => 5,
                    'diggCount' => 2,
                    'commentCount' => 0,
                    'shareCount' => 0,
                ],
            ]);
        $client->shouldReceive('runActor')
            ->once()
            ->withArgs(function (string $actorId, array $input): bool {
                return $actorId === 'clockworks/tiktok-scraper'
                    && ($input['shouldDownloadVideos'] ?? null) === true
                    && ($input['postURLs'] ?? null) === ['https://www.tiktok.com/@rivalbakery/video/222'];
            })
            ->andReturn([[
                'id' => 'new_tt',
                'webVideoUrl' => 'https://www.tiktok.com/@rivalbakery/video/222',
                'videoUrl' => 'https://cdn.tiktokcdn.com/new.mp4',
                'text' => 'New',
                'createTime' => now()->subDay()->timestamp,
                'playCount' => 5,
                'diggCount' => 2,
                'commentCount' => 0,
                'shareCount' => 0,
            ]]);
        $this->app->instance(ApifyClient::class, $client);

        config([
            'snitch.apify.actors.tiktok' => 'clockworks/tiktok-scraper',
            'snitch.sync.recency_days' => 30,
            'snitch.sync.posts_limit' => 12,
        ]);

        (new SyncTrackedAccountJob($account->id))->handle(app(PlatformAdapterManager::class), app(SnitchAnalyticsService::class), app(VendorUsageCharger::class));

        $this->assertSame(2, Post::query()->count());
        $new = Post::query()->where('external_id', 'new_tt')->first();
        $this->assertNotNull($new);
        $this->assertSame('https://cdn.tiktokcdn.com/new.mp4', $new->media_url);
        Queue::assertPushed(AnalyzePostJob::class, fn (AnalyzePostJob $job) => $job->postId === $new->id);
    }
}
