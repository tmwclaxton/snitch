<?php

namespace Tests\Unit\Apify;

use App\Enums\PostType;
use App\Services\Apify\Adapters\FacebookAdapter;
use App\Services\Apify\Adapters\InstagramAdapter;
use App\Services\Apify\Adapters\LinkedInAdapter;
use App\Services\Apify\Adapters\TikTokAdapter;
use App\Services\Apify\Adapters\YoutubeAdapter;
use App\Services\Apify\ApifyClient;
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
        $adapter = new $adapterClass($client);

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

    public function test_youtube_imports_shorts_only(): void
    {
        $adapter = new YoutubeAdapter($this->createMock(ApifyClient::class));
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
        $adapter = new YoutubeAdapter($this->createMock(ApifyClient::class));
        $items = json_decode(
            (string) file_get_contents(base_path('tests/Fixtures/Apify/youtube.json')),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $about = collect($items)->first(fn (array $item): bool => ($item['error'] ?? null) === 'CHANNEL_HAS_NO_SHORTS');

        $this->assertIsArray($about);

        $client = $this->createMock(ApifyClient::class);
        $client->method('runActor')->willReturn([$about]);
        $adapter = new YoutubeAdapter($client);

        $profile = $adapter->resolveProfile('rivalbakery');

        $this->assertSame('rivalbakery', $profile['handle']);
        $this->assertSame('UCrival1', $profile['external_id']);
        $this->assertSame('Rival Bakery', $profile['display_name']);
    }
}
