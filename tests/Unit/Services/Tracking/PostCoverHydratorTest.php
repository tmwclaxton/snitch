<?php

namespace Tests\Unit\Services\Tracking;

use App\Enums\Platform;
use App\Models\Post;
use App\Services\Tracking\PostCoverHydrator;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PostCoverHydratorTest extends TestCase
{
    public function test_discover_returns_payload_cover_without_http(): void
    {
        Http::fake();

        $post = new Post([
            'platform' => Platform::Instagram,
            'url' => 'https://www.instagram.com/reel/CxYz123AbCd/',
            'media_url' => 'https://cdn.example.com/ig1.mp4',
            'raw_payload' => [
                'displayUrl' => 'https://cdn.example.com/ig1.jpg',
            ],
        ]);

        $url = app(PostCoverHydrator::class)->discover($post, fetchRemote: true);

        $this->assertSame('https://cdn.example.com/ig1.jpg', $url);
        Http::assertNothingSent();
    }

    public function test_discover_fetches_tiktok_oembed_when_needed(): void
    {
        Http::fake([
            'https://www.tiktok.com/oembed*' => Http::response([
                'thumbnail_url' => 'https://cdn.example.com/tt-cover.jpg',
            ], 200),
        ]);

        $post = new Post([
            'platform' => Platform::TikTok,
            'url' => 'https://www.tiktok.com/@rivalbakery/video/1',
            'media_url' => 'https://cdn.example.com/tt1.mp4',
            'raw_payload' => [],
        ]);

        $url = app(PostCoverHydrator::class)->discover($post, fetchRemote: true);

        $this->assertSame('https://cdn.example.com/tt-cover.jpg', $url);
    }
}
