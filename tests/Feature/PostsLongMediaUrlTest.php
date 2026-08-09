<?php

namespace Tests\Feature;

use App\Enums\Platform;
use App\Jobs\AnalyzePostJob;
use App\Jobs\ScoreWinnersJob;
use App\Jobs\SyncTrackedAccountJob;
use App\Models\Post;
use App\Models\TrackedAccount;
use App\Models\User;
use App\Services\Apify\ApifyClient;
use App\Services\Apify\PlatformAdapterManager;
use App\Services\SnitchAnalyticsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class PostsLongMediaUrlTest extends TestCase
{
    use RefreshDatabase;

    public function test_sync_persists_facebook_cdn_media_urls_over_255_chars(): void
    {
        Queue::fake([AnalyzePostJob::class, ScoreWinnersJob::class]);

        $user = User::factory()->create();
        $account = TrackedAccount::factory()->for($user)->create([
            'platform' => Platform::Facebook,
            'handle' => 'CandidDotOrg',
        ]);

        $mediaUrl = 'https://video-iad3-1.xx.fbcdn.net/o1/v/t2/f2/m366/AQNcLDEwKjwpWOA3yrGsGI9wfpvqABFQdJq8sotWl8_Qd2myNuYqWcvNvfQcNXxdvyzaC2N3t4Ei0JFO7gG7lMKJmkML4ZJan7bkBxzPEYrLBA.mp4?_nc_cat=108&_nc_sid=5e9851&_nc_ht=video-iad3-1.xx.fbcdn.net&_nc_ohc=KZNy4mxBwl8Q7kNvwHZsAU1&efg=eyJ2ZW5jb2RlX3RhZyI6Inhwdl9wcm9ncmVzc2l2ZS5GQUNFQk9PSy4uQzMuNzIwLmRhc2hfaDI2NC1iYXNpYy1nZW4yXzcyMHAiLCJ4cHZfYXNzZXRfaWQiOjI3NjQ4NzQxNTY4MDgwOTYzLCJhc3NldF9hZ2VfZGF5cyI6MjQsInZpX3VzZWNhc2VfaWQiOjEwMTIyLCJkdXJhdGlvbl9zIjo2NCwidXJsZ2VuX3NvdXJjZSI6Ind3dyJ9&ccb=17-1&vs=e2e05d1f969e6311&_nc_vs=HBksFQIYRWZiX2VwaGVtZXJhbC8zRTRCRjA1QjkyODVBMjI3NzlBODA2RjMxNjdBMEZCMl9tdF8xX3ZpZGVvX2Rhc2hpbml0Lm1wNBUAAsgBEgAVAhhAZmJfcGVybWFuZW50L0E5NDEzQzg5OTQ2Mzg3MzVBQzJGQjQ4QzRFRkYzM0FEX2F1ZGlvX2Rhc2hpbml0Lm1wNBUCAsgBEgAoABgAGwKIB3VzZV9vaWwBMRJwcm9ncmVzc2l2ZV9yZWNpcGUBMRUAACaGsbe6yZidYhUCKAJDMywXQFAZmZmZmZoYGWRhc2hfaDI2NC1iYXNpYy1nZW4yXzcyMHARAHUCZZSeAQA&_nc_gid=8_9sHRtrdG0Eb97iRQZlIw&_nc_ss=7c289&_nc_zt=28&oh=00_AQG527cY665w0BzDOCOmyZ8Ps41BVtZy-PyaH_SWryoNyw&oe=6A7D6C80&bitrate=682246&tag=dash_h264-basic-gen2_720p';

        $this->assertGreaterThan(255, strlen($mediaUrl));

        $client = Mockery::mock(ApifyClient::class);
        $client->shouldReceive('runActor')->andReturn([
            [
                'pageName' => 'Candid',
                'pageId' => 'page_1',
                'pageProfilePictureUrl' => null,
                'postId' => '1488414446665060',
                'url' => 'https://www.facebook.com/reel/1526149895912214/',
                'text' => 'Long CDN media_url fixture',
                'time' => now()->subDays(2)->toIso8601String(),
                'type' => 'video',
                'videoUrl' => $mediaUrl,
                'likes' => 17,
                'comments' => 4,
                'shares' => 1,
                'viewsCount' => 859,
            ],
        ]);
        $this->app->instance(ApifyClient::class, $client);

        config([
            'snitch.sync.recency_days' => 30,
            'snitch.sync.posts_limit' => 12,
        ]);

        (new SyncTrackedAccountJob($account->id))->handle(
            app(PlatformAdapterManager::class),
            app(SnitchAnalyticsService::class),
        );

        $account->refresh();
        $this->assertSame('success', $account->last_sync_status);
        $this->assertNull($account->last_sync_error);

        $post = Post::query()->first();
        $this->assertNotNull($post);
        $this->assertSame($mediaUrl, $post->media_url);
        $this->assertGreaterThan(255, strlen((string) $post->media_url));
    }
}
