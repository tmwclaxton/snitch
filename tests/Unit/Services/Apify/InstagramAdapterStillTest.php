<?php

namespace Tests\Unit\Services\Apify;

use App\Enums\PostType;
use App\Services\Apify\Adapters\InstagramAdapter;
use Tests\TestCase;

class InstagramAdapterStillTest extends TestCase
{
    public function test_map_post_keeps_instagram_photos_and_carousels(): void
    {
        $adapter = app(InstagramAdapter::class);
        $method = new \ReflectionMethod(InstagramAdapter::class, 'mapPost');

        $photo = $method->invoke($adapter, [
            'id' => 'photo_1',
            'shortCode' => 'PHOTO1',
            'url' => 'https://www.instagram.com/p/PHOTO1/',
            'type' => 'Image',
            'caption' => 'Studio still',
            'displayUrl' => 'https://cdn.example.com/still.jpg',
            'likesCount' => 40,
            'commentsCount' => 2,
        ], 'letsgosocialuk');

        $this->assertIsArray($photo);
        $this->assertSame(PostType::Image->value, $photo['type']);
        $this->assertSame('https://cdn.example.com/still.jpg', $photo['media_url']);

        $carousel = $method->invoke($adapter, [
            'id' => 'side_1',
            'shortCode' => 'SIDE1',
            'url' => 'https://www.instagram.com/p/SIDE1/',
            'type' => 'Sidecar',
            'caption' => 'Carousel brief',
            'displayUrl' => 'https://cdn.example.com/slide.jpg',
            'likesCount' => 80,
            'commentsCount' => 4,
        ], 'letsgosocialuk');

        $this->assertIsArray($carousel);
        $this->assertSame(PostType::Carousel->value, $carousel['type']);
        $this->assertSame('https://cdn.example.com/slide.jpg', $carousel['media_url']);
    }

    public function test_map_post_still_requires_video_for_reels(): void
    {
        $adapter = app(InstagramAdapter::class);
        $method = new \ReflectionMethod(InstagramAdapter::class, 'mapPost');

        $reel = $method->invoke($adapter, [
            'id' => 'reel_1',
            'shortCode' => 'REEL1',
            'url' => 'https://www.instagram.com/reel/REEL1/',
            'type' => 'Video',
            'productType' => 'clips',
            'displayUrl' => 'https://cdn.example.com/cover.jpg',
        ], 'letsgosocialuk');

        $this->assertNull($reel);
    }
}
