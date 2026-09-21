<?php

namespace Tests\Unit\Support;

use App\Enums\Platform;
use App\Models\Post;
use App\Support\PostCover;
use Tests\TestCase;

class PostCoverTest extends TestCase
{
    public function test_prefers_instagram_display_url_over_video_media(): void
    {
        $post = new Post([
            'platform' => Platform::Instagram,
            'url' => 'https://www.instagram.com/reel/CxYz123AbCd/',
            'media_url' => 'https://cdn.example.com/ig1.mp4',
            'raw_payload' => [
                'displayUrl' => 'https://cdn.example.com/ig1.jpg',
            ],
        ]);

        $this->assertSame('https://cdn.example.com/ig1.jpg', PostCover::resolve($post));
    }

    public function test_reads_tiktok_origin_cover_from_video_meta(): void
    {
        $post = new Post([
            'platform' => Platform::TikTok,
            'url' => 'https://www.tiktok.com/@rivalbakery/video/1',
            'media_url' => 'https://cdn.example.com/tt1.mp4',
            'raw_payload' => [
                'videoMeta' => [
                    'originCover' => 'https://cdn.example.com/tt1-cover.jpg',
                ],
            ],
        ]);

        $this->assertSame('https://cdn.example.com/tt1-cover.jpg', PostCover::resolve($post));
    }

    public function test_builds_a_youtube_thumbnail_from_the_watch_url(): void
    {
        $post = new Post([
            'platform' => Platform::Youtube,
            'url' => 'https://www.youtube.com/shorts/ytShort1',
            'media_url' => 'https://cdn.example.com/yt-short1.mp4',
            'raw_payload' => [],
        ]);

        $this->assertSame(
            'https://i.ytimg.com/vi/ytShort1/hqdefault.jpg',
            PostCover::resolve($post),
        );
    }

    public function test_skips_avatars_and_video_files(): void
    {
        $post = new Post([
            'platform' => Platform::Instagram,
            'url' => 'https://www.instagram.com/p/ABC123/',
            'media_url' => 'https://cdn.example.com/ig1.mp4',
            'raw_payload' => [
                'thumbnail' => 'https://cdn.example.com/avatar.jpg',
            ],
        ]);

        $this->assertNull(PostCover::resolve($post));
    }

    public function test_uses_image_media_url_when_payload_has_no_cover(): void
    {
        $post = new Post([
            'platform' => Platform::Facebook,
            'url' => 'https://www.facebook.com/photo.php?fbid=1',
            'media_url' => 'https://cdn.example.com/photo.png',
            'raw_payload' => [],
        ]);

        $this->assertSame('https://cdn.example.com/photo.png', PostCover::resolve($post));
    }
}
