<?php

namespace Tests\Unit\Services\Scraping;

use App\Services\Scraping\YoutubeMediaHydrator;
use App\Services\TikHub\TikHubClient;
use Mockery;
use Tests\TestCase;

class YoutubeMediaHydratorTest extends TestCase
{
    public function test_extracts_video_id_from_shorts_and_watch_urls(): void
    {
        $hydrator = app(YoutubeMediaHydrator::class);

        $this->assertSame('O2mqPVNBq4A', $hydrator->extractVideoId(null, 'https://www.youtube.com/shorts/O2mqPVNBq4A'));
        $this->assertSame('O2mqPVNBq4A', $hydrator->extractVideoId(null, 'https://www.youtube.com/watch?v=O2mqPVNBq4A'));
        $this->assertSame('O2mqPVNBq4A', $hydrator->extractVideoId('O2mqPVNBq4A', null));
    }

    public function test_page_urls_are_not_downloadable_media(): void
    {
        $hydrator = app(YoutubeMediaHydrator::class);

        $this->assertFalse($hydrator->isDownloadableMediaUrl('https://www.youtube.com/shorts/abc123'));
        $this->assertTrue($hydrator->isDownloadableMediaUrl('https://cdn.example.com/clip.mp4'));
        $this->assertTrue($hydrator->isDownloadableMediaUrl('https://googlevideo.com/videoplayback?mime=video'));
    }

    public function test_pick_download_url_prefers_muxed_progressive_mp4(): void
    {
        $hydrator = app(YoutubeMediaHydrator::class);

        $url = $hydrator->pickDownloadUrl([
            'data' => [
                'streamingData' => [
                    'formats' => [
                        [
                            'url' => 'https://googlevideo.com/muxed-360.mp4',
                            'mimeType' => 'video/mp4; codecs="avc1, mp4a"',
                            'height' => 360,
                        ],
                        [
                            'url' => 'https://googlevideo.com/muxed-720.mp4',
                            'mimeType' => 'video/mp4; codecs="avc1, mp4a"',
                            'height' => 720,
                        ],
                    ],
                    'adaptiveFormats' => [
                        [
                            'url' => 'https://googlevideo.com/adaptive-1080.mp4',
                            'mimeType' => 'video/mp4; codecs="avc1"',
                            'height' => 1080,
                        ],
                    ],
                ],
            ],
        ]);

        $this->assertSame('https://googlevideo.com/muxed-720.mp4', $url);
    }

    public function test_hydrate_posts_resolves_page_media_via_tikhub(): void
    {
        config(['snitch.tikhub.api_key' => 'tikhub-test']);

        $client = Mockery::mock(TikHubClient::class);
        $client->shouldReceive('configured')->andReturn(true);
        $client->shouldReceive('get')
            ->once()
            ->with('/api/v1/youtube/web/get_video_info_v2', ['video_id' => 'abc123XYZ'], 'youtube')
            ->andReturn([
                'code' => 200,
                'data' => [
                    'streamingData' => [
                        'formats' => [[
                            'url' => 'https://googlevideo.com/short.mp4',
                            'mimeType' => 'video/mp4',
                            'height' => 360,
                        ]],
                    ],
                ],
            ]);

        $hydrator = new YoutubeMediaHydrator($client);

        $posts = $hydrator->hydratePosts([[
            'external_id' => 'abc123XYZ',
            'url' => 'https://www.youtube.com/shorts/abc123XYZ',
            'posted_at' => now()->toIso8601String(),
            'type' => 'reel',
            'caption' => 'Short',
            'media_url' => 'https://www.youtube.com/shorts/abc123XYZ',
            'metrics' => [],
            'raw_payload' => [],
        ]]);

        $this->assertCount(1, $posts);
        $this->assertSame('https://googlevideo.com/short.mp4', $posts[0]['media_url']);
    }

    public function test_hydrate_posts_drops_unresolved_page_media(): void
    {
        config(['snitch.tikhub.api_key' => '']);

        $client = Mockery::mock(TikHubClient::class);
        $client->shouldReceive('configured')->andReturn(false);
        $client->shouldReceive('get')->never();

        $hydrator = new YoutubeMediaHydrator($client);

        $posts = $hydrator->hydratePosts([[
            'external_id' => 'abc123XYZ',
            'url' => 'https://www.youtube.com/shorts/abc123XYZ',
            'posted_at' => now()->toIso8601String(),
            'type' => 'reel',
            'caption' => 'Short',
            'media_url' => 'https://www.youtube.com/shorts/abc123XYZ',
            'metrics' => [],
            'raw_payload' => [],
        ]]);

        $this->assertSame([], $posts);
    }
}
