<?php

namespace Tests\Unit;

use App\Services\Blog\NanoGptBlogHeroImageService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class NanoGptBlogHeroImageServiceTest extends TestCase
{
    public function test_skips_when_api_key_missing(): void
    {
        config(['snitch.nanogpt.api_key' => '']);

        $path = app(NanoGptBlogHeroImageService::class)->generateAndStore('soft risograph hero');

        $this->assertNull($path);
    }

    public function test_stores_downloaded_image_bytes(): void
    {
        Storage::fake('public');
        config([
            'snitch.nanogpt.api_key' => 'test-key',
            'blog.hero_image_disk' => 'public',
            'blog.hero_image_path_prefix' => 'blogs/heroes',
            'blog.image.base_url' => 'https://nano-gpt.test/api/v1',
            'blog.image.model' => 'flux-schnell',
            'blog.image.size' => '1792x1024',
        ]);

        Http::fake([
            'https://nano-gpt.test/api/v1/images/generations' => Http::response([
                'data' => [
                    ['url' => 'https://cdn.example.test/hero.png'],
                ],
            ]),
            'https://cdn.example.test/hero.png' => Http::response('fake-png-bytes', 200),
        ]);

        $path = app(NanoGptBlogHeroImageService::class)->generateAndStore('soft risograph contact sheet');

        $this->assertIsString($path);
        $this->assertStringStartsWith('blogs/heroes/', $path);
        Storage::disk('public')->assertExists($path);
    }
}
