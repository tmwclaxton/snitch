<?php

namespace Tests\Unit\Apify;

use App\Enums\PostType;
use App\Services\Apify\Adapters\FacebookAdapter;
use App\Services\Apify\Adapters\InstagramAdapter;
use App\Services\Apify\Adapters\LinkedInAdapter;
use App\Services\Apify\Adapters\TikTokAdapter;
use App\Services\Apify\Adapters\YoutubeAdapter;
use App\Services\Apify\ApifyClient;
use App\Services\Scraping\YoutubeMediaHydrator;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PlatformAdapterTest extends TestCase
{
    /**
     * @return array<string, array{0: class-string, 1: string}>
     */
    public static function adapters(): array
    {
        return [
            'instagram' => [InstagramAdapter::class, 'instagram.json'],
            'tiktok' => [TikTokAdapter::class, 'tiktok.json'],
            'facebook' => [FacebookAdapter::class, 'facebook.json'],
            'linkedin' => [LinkedInAdapter::class, 'linkedin.json'],
            'youtube' => [YoutubeAdapter::class, 'youtube.json'],
        ];
    }

    #[DataProvider('adapters')]
    public function test_adapter_maps_fixture_to_normalized_post_shape(string $adapterClass, string $fixture): void
    {
        $client = $this->createMock(ApifyClient::class);
        /** @var InstagramAdapter|TikTokAdapter|FacebookAdapter|LinkedInAdapter|YoutubeAdapter $adapter */
        $adapter = $this->makeAdapter($adapterClass, $client);

        $items = json_decode(
            (string) file_get_contents(base_path('tests/Fixtures/Apify/'.$fixture)),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        $posts = $adapter->mapFixturePosts($items, 'rivalbakery');

        $this->assertNotEmpty($posts);
        $post = $posts[0];

        foreach (['external_id', 'url', 'posted_at', 'type', 'caption', 'media_url', 'metrics', 'raw_payload'] as $field) {
            $this->assertArrayHasKey($field, $post);
        }

        $this->assertNotSame('', (string) $post['external_id']);
        $this->assertNotSame('', (string) $post['url']);
        $this->assertNotNull($post['posted_at']);
        $this->assertContains($post['type'], PostType::analyzableValues());
        $this->assertNotSame('', (string) $post['media_url']);
        $this->assertIsArray($post['metrics']);
        $this->assertIsArray($post['raw_payload']);
    }

    public function test_facebook_prefers_native_mp4_over_reel_page_url(): void
    {
        $adapter = new FacebookAdapter($this->createMock(ApifyClient::class));

        $posts = $adapter->mapFixturePosts([
            [
                'postId' => 'fb_video_thumb',
                'url' => 'https://facebook.com/rivalbakery/videos/99',
                'text' => 'Video with poster',
                'time' => '2026-08-02T12:00:00+00:00',
                'isVideo' => true,
                'image' => 'https://cdn.example.com/poster.jpg',
                'media' => [[
                    'url' => 'https://www.facebook.com/reel/99/',
                    'videoDeliveryLegacyFields' => [
                        'browser_native_hd_url' => 'https://video.fbcdn.net/v/real-video.mp4',
                    ],
                ]],
                'likes' => 10,
            ],
        ], 'rivalbakery');

        $this->assertCount(1, $posts);
        $this->assertSame('https://video.fbcdn.net/v/real-video.mp4', $posts[0]['media_url']);
        $this->assertContains($posts[0]['type'], PostType::analyzableValues());
    }

    public function test_facebook_skips_image_only_posts(): void
    {
        $adapter = new FacebookAdapter($this->createMock(ApifyClient::class));
        $items = json_decode(
            (string) file_get_contents(base_path('tests/Fixtures/Apify/facebook-image.json')),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        $this->assertSame([], $adapter->mapFixturePosts($items, 'rivalbakery'));
    }

    public function test_linkedin_skips_image_only_posts(): void
    {
        $adapter = new LinkedInAdapter($this->createMock(ApifyClient::class));
        $items = json_decode(
            (string) file_get_contents(base_path('tests/Fixtures/Apify/linkedin.json')),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        $posts = $adapter->mapFixturePosts($items, 'rivalbakery');

        $this->assertCount(1, $posts);
        $this->assertSame('7123456789012345678', $posts[0]['external_id']);
        $this->assertSame('https://cdn.example.com/li1.mp4', $posts[0]['media_url']);
    }

    public function test_linkedin_resolve_profile_uses_company_posts_input_and_source_company(): void
    {
        $items = json_decode(
            (string) file_get_contents(base_path('tests/Fixtures/Apify/linkedin.json')),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        $client = $this->createMock(ApifyClient::class);
        $client->expects($this->once())
            ->method('runActor')
            ->with(
                'apimaestro/linkedin-company-posts',
                $this->callback(function (array $input): bool {
                    return ($input['company_name'] ?? null) === 'rivalbakery'
                        && ($input['limit'] ?? null) === 1;
                }),
            )
            ->willReturn([$items[0]]);

        config([
            'snitch.apify.actors.linkedin' => 'apimaestro/linkedin-company-posts',
            'snitch.apify.actors.linkedin_profile' => 'apimaestro/linkedin-profile-posts',
        ]);

        $profile = (new LinkedInAdapter($client))->resolveProfile('https://linkedin.com/company/rivalbakery');

        $this->assertSame('rivalbakery', $profile['handle']);
        $this->assertSame('rivalbakery', $profile['external_id']);
        $this->assertSame('Rival Bakery', $profile['display_name']);
        $this->assertSame('https://cdn.example.com/li-avatar.jpg', $profile['avatar']);
    }

    public function test_linkedin_resolve_profile_uses_profile_actor_for_in_urls(): void
    {
        $client = $this->createMock(ApifyClient::class);
        $client->expects($this->once())
            ->method('runActor')
            ->with(
                'apimaestro/linkedin-profile-posts',
                $this->callback(function (array $input): bool {
                    return ($input['username'] ?? null) === 'satyanadella'
                        && ($input['limit'] ?? null) === 1;
                }),
            )
            ->willReturn([[
                'full_urn' => 'urn:li:ugcPost:1',
                'url' => 'https://www.linkedin.com/posts/satyanadella-activity-1',
                'author' => [
                    'first_name' => 'Satya',
                    'last_name' => 'Nadella',
                    'username' => 'satyanadella',
                    'profile_url' => 'https://www.linkedin.com/in/satyanadella',
                    'profile_picture' => 'https://cdn.example.com/satya.jpg',
                ],
            ]]);

        config([
            'snitch.apify.actors.linkedin' => 'apimaestro/linkedin-company-posts',
            'snitch.apify.actors.linkedin_profile' => 'apimaestro/linkedin-profile-posts',
        ]);

        $profile = (new LinkedInAdapter($client))->resolveProfile('https://linkedin.com/in/satyanadella');

        $this->assertSame('satyanadella', $profile['handle']);
        $this->assertSame('satyanadella', $profile['external_id']);
        $this->assertSame('Satya Nadella', $profile['display_name']);
        $this->assertSame('https://linkedin.com/in/satyanadella', $profile['url']);
    }

    /**
     * @param  class-string  $adapterClass
     */
    private function makeAdapter(string $adapterClass, ApifyClient $client): InstagramAdapter|TikTokAdapter|FacebookAdapter|LinkedInAdapter|YoutubeAdapter
    {
        if ($adapterClass === YoutubeAdapter::class) {
            return new YoutubeAdapter($client, app(YoutubeMediaHydrator::class));
        }

        return new $adapterClass($client);
    }

    public function test_youtube_imports_shorts_only(): void
    {
        $adapter = $this->makeAdapter(YoutubeAdapter::class, $this->createMock(ApifyClient::class));
        $items = json_decode(
            (string) file_get_contents(base_path('tests/Fixtures/Apify/youtube.json')),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        $posts = $adapter->mapFixturePosts($items, 'rivalbakery');

        $this->assertCount(1, $posts);
        $this->assertSame('ytShort1', $posts[0]['external_id']);
        $this->assertSame(PostType::Reel->value, $posts[0]['type']);
        $this->assertStringContainsString('/shorts/', $posts[0]['url']);
    }

    public function test_youtube_maps_profile_when_channel_has_no_shorts(): void
    {
        $items = json_decode(
            (string) file_get_contents(base_path('tests/Fixtures/Apify/youtube.json')),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $about = collect($items)->first(fn (array $item): bool => ($item['error'] ?? null) === 'CHANNEL_HAS_NO_SHORTS');

        $this->assertIsArray($about);

        $client = $this->createMock(ApifyClient::class);
        $client->method('runActor')->willReturn([$about]);
        $adapter = $this->makeAdapter(YoutubeAdapter::class, $client);

        $profile = $adapter->resolveProfile('rivalbakery');

        $this->assertSame('rivalbakery', $profile['handle']);
        $this->assertSame('UCrival1', $profile['external_id']);
        $this->assertSame('Rival Bakery', $profile['display_name']);
    }

    public function test_tiktok_list_input_disables_video_download(): void
    {
        $client = $this->createMock(ApifyClient::class);
        $client->expects($this->once())
            ->method('runActor')
            ->with(
                'clockworks/tiktok-scraper',
                $this->callback(function (array $input): bool {
                    return ($input['shouldDownloadVideos'] ?? null) === false
                        && ($input['profiles'][0] ?? null) === 'rivalbakery'
                        && isset($input['oldestPostDateUnified']);
                }),
            )
            ->willReturn([]);

        config(['snitch.apify.actors.tiktok' => 'clockworks/tiktok-scraper']);

        (new TikTokAdapter($client))->listRecentPosts('rivalbakery', 12);
    }

    public function test_tiktok_maps_metadata_without_media_url(): void
    {
        $adapter = new TikTokAdapter($this->createMock(ApifyClient::class));

        $posts = $adapter->mapFixturePosts([
            [
                'id' => 'tt_meta_1',
                'webVideoUrl' => 'https://www.tiktok.com/@rivalbakery/video/123',
                'text' => 'No download yet',
                'createTime' => now()->subDay()->timestamp,
                'playCount' => 10,
                'diggCount' => 2,
                'commentCount' => 0,
                'shareCount' => 0,
            ],
        ], 'rivalbakery');

        $this->assertCount(1, $posts);
        $this->assertSame('tt_meta_1', $posts[0]['external_id']);
        $this->assertNull($posts[0]['media_url']);
        $this->assertSame(PostType::Reel->value, $posts[0]['type']);
    }

    public function test_tiktok_hydrate_media_urls_downloads_via_post_urls(): void
    {
        $client = $this->createMock(ApifyClient::class);
        $client->expects($this->once())
            ->method('runActor')
            ->with(
                'clockworks/tiktok-scraper',
                $this->callback(function (array $input): bool {
                    return ($input['shouldDownloadVideos'] ?? null) === true
                        && ($input['postURLs'] ?? null) === ['https://www.tiktok.com/@rivalbakery/video/123']
                        && ($input['resultsPerPage'] ?? null) === 1;
                }),
            )
            ->willReturn([[
                'id' => 'tt_meta_1',
                'webVideoUrl' => 'https://www.tiktok.com/@rivalbakery/video/123',
                'videoUrl' => 'https://cdn.tiktokcdn.com/video123.mp4',
                'text' => 'Downloaded',
                'createTime' => now()->subDay()->timestamp,
                'playCount' => 10,
                'diggCount' => 2,
                'commentCount' => 0,
                'shareCount' => 0,
            ]]);

        config(['snitch.apify.actors.tiktok' => 'clockworks/tiktok-scraper']);

        $hydrated = (new TikTokAdapter($client))->hydrateMediaUrls([
            [
                'external_id' => 'tt_meta_1',
                'url' => 'https://www.tiktok.com/@rivalbakery/video/123',
                'posted_at' => now()->subDay()->toIso8601String(),
                'type' => PostType::Reel->value,
                'caption' => 'No download yet',
                'media_url' => null,
                'metrics' => ['views' => 10, 'likes' => 2, 'comments' => 0, 'shares' => 0],
                'raw_payload' => [],
            ],
        ]);

        $this->assertCount(1, $hydrated);
        $this->assertSame('https://cdn.tiktokcdn.com/video123.mp4', $hydrated[0]['media_url']);
    }

    public function test_instagram_list_input_uses_since_date_and_multiplier(): void
    {
        $client = $this->createMock(ApifyClient::class);
        $since = CarbonImmutable::parse('2026-08-01');

        $client->expects($this->once())
            ->method('runActor')
            ->with(
                'apify/instagram-scraper',
                $this->callback(function (array $input) use ($since): bool {
                    return ($input['onlyPostsNewerThan'] ?? null) === $since->toDateString()
                        && ($input['resultsLimit'] ?? 0) >= 30;
                }),
            )
            ->willReturn([]);

        config([
            'snitch.apify.actors.instagram' => 'apify/instagram-scraper',
            'snitch.sync.fetch_multipliers.instagram' => 2.5,
            'snitch.sync.posts_limit' => 12,
            'snitch.sync.recency_days' => 30,
        ]);

        (new InstagramAdapter($client))->listRecentPosts('rivalbakery', 12, $since);
    }

    public function test_youtube_list_input_uses_since_date(): void
    {
        $client = $this->createMock(ApifyClient::class);
        $since = CarbonImmutable::parse('2026-08-02');

        $client->expects($this->once())
            ->method('runActor')
            ->with(
                'streamers/youtube-scraper',
                $this->callback(function (array $input) use ($since): bool {
                    return ($input['oldestPostDate'] ?? null) === $since->toDateString()
                        && ($input['maxResultsShorts'] ?? null) === 12;
                }),
            )
            ->willReturn([]);

        config([
            'snitch.apify.actors.youtube' => 'streamers/youtube-scraper',
            'snitch.sync.fetch_multipliers.youtube' => 1.0,
            'snitch.sync.recency_days' => 30,
        ]);

        $this->makeAdapter(YoutubeAdapter::class, $client)->listRecentPosts('rivalbakery', 12, $since);
    }

    public function test_linkedin_rejects_linkedin_page_media_urls(): void
    {
        $adapter = new LinkedInAdapter($this->createMock(ApifyClient::class));

        $posts = $adapter->mapFixturePosts([[
            'activity_urn' => 'urn:li:activity:1',
            'post_url' => 'https://www.linkedin.com/posts/rivalbakery-1',
            'text' => 'Page media',
            'posted_at' => now()->subDay()->toIso8601String(),
            'videoUrl' => 'https://www.linkedin.com/posts/rivalbakery-1',
            'type' => 'video',
        ]], 'rivalbakery');

        $this->assertSame([], $posts);
    }

    public function test_youtube_map_post_does_not_store_shorts_page_as_media_url(): void
    {
        $adapter = $this->makeAdapter(YoutubeAdapter::class, $this->createMock(ApifyClient::class));

        $posts = $adapter->mapFixturePosts([[
            'id' => 'pageOnly1',
            'url' => 'https://www.youtube.com/shorts/pageOnly1',
            'title' => 'Page only',
            'date' => now()->subDay()->toIso8601String(),
            'isShort' => true,
            'viewCount' => 10,
        ]], 'rivalbakery');

        $this->assertCount(1, $posts);
        $this->assertNull($posts[0]['media_url']);
        $this->assertSame('https://www.youtube.com/shorts/pageOnly1', $posts[0]['url']);
    }
}
