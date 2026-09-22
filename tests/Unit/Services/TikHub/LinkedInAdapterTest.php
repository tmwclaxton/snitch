<?php

namespace Tests\Unit\Services\TikHub;

use App\Services\TikHub\Adapters\LinkedInAdapter;
use App\Services\TikHub\TikHubClient;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class LinkedInAdapterTest extends TestCase
{
    #[Test]
    public function test_company_posts_keep_video_stream_and_drop_images(): void
    {
        config([
            'snitch.tikhub.endpoints.linkedin.company_posts' => '/api/v1/linkedin/web_v2/get_company_posts',
        ]);

        $client = $this->createMock(TikHubClient::class);
        $client->expects($this->once())
            ->method('get')
            ->with(
                '/api/v1/linkedin/web_v2/get_company_posts',
                ['url' => 'https://www.linkedin.com/company/we-are-social'],
                'linkedin',
            )
            ->willReturn([
                'data' => [
                    'data' => [
                        [
                            'url' => 'https://www.linkedin.com/posts/we-are-social_photo-activity-1',
                            'urn' => '1',
                            'text' => 'A still',
                            'posted' => '2026-09-22 06:32:34',
                            'images' => [['url' => 'https://media.licdn.com/dms/image/still.jpg']],
                            'num_likes' => 4,
                        ],
                        [
                            'url' => 'https://www.linkedin.com/posts/we-are-social_clip-activity-2',
                            'urn' => '7508050594813833216',
                            'text' => 'A short clip',
                            'posted' => '2026-09-15 09:02:08',
                            'num_likes' => 6,
                            'num_comments' => 1,
                            'num_reposts' => 2,
                            'video' => [
                                'duration' => 37366,
                                'stream_url' => 'https://dms.licdn.com/playlist/vid/v2/clip/mp4-720p/file',
                            ],
                        ],
                    ],
                    'paging' => ['count' => 2],
                ],
            ]);

        $posts = (new LinkedInAdapter($client))->listRecentPosts('we-are-social', 8);

        $this->assertCount(1, $posts);
        $this->assertSame('7508050594813833216', $posts[0]['external_id']);
        $this->assertSame('https://dms.licdn.com/playlist/vid/v2/clip/mp4-720p/file', $posts[0]['media_url']);
        $this->assertSame('reel', $posts[0]['type']);
        $this->assertSame('2026-09-15T09:02:08+00:00', $posts[0]['posted_at']);
        $this->assertSame(6, $posts[0]['metrics']['likes']);
        $this->assertSame(1, $posts[0]['metrics']['comments']);
        $this->assertSame(2, $posts[0]['metrics']['shares']);
    }
}
