<?php

namespace Tests\Unit\Services\Tracking;

use App\Enums\Platform;
use App\Models\Post;
use App\Models\TrackedAccount;
use App\Models\User;
use App\Services\Tracking\PostCoverHydrator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PostCoverHydratorTest extends TestCase
{
    use RefreshDatabase;

    private const JPEG = "\xFF\xD8\xFF\xD9";

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

    public function test_persist_copies_a_signed_still_onto_the_public_disk(): void
    {
        Storage::fake('public');
        Http::fake([
            'https://cdn.example.com/ig1.jpg' => Http::response(self::JPEG, 200, ['Content-Type' => 'image/jpeg']),
        ]);

        $post = $this->instagramPost('https://cdn.example.com/ig1.jpg');

        $url = app(PostCoverHydrator::class)->persist($post);

        $this->assertSame('/storage/post-covers/'.$post->id.'.jpg', $url);
        Storage::disk('public')->assertExists('post-covers/'.$post->id.'.jpg');
        Http::assertSent(fn ($request): bool => $request->url() === 'https://cdn.example.com/ig1.jpg');
    }

    public function test_persist_keeps_a_local_cover_without_downloading_again(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('post-covers/1.jpg', self::JPEG);
        Http::fake();

        $account = TrackedAccount::factory()->for(User::factory())->create();
        $post = Post::factory()->forAccount($account)->create([
            'platform' => Platform::Instagram,
            'url' => 'https://www.instagram.com/reel/CxYz123AbCd/',
            'cover_url' => '/storage/post-covers/1.jpg',
            'raw_payload' => [
                'displayUrl' => 'https://cdn.example.com/fresh.jpg',
            ],
        ]);
        Storage::disk('public')->put('post-covers/'.$post->id.'.jpg', self::JPEG);
        $post->forceFill(['cover_url' => '/storage/post-covers/'.$post->id.'.jpg'])->save();

        $url = app(PostCoverHydrator::class)->persist($post, fetchRemote: true);

        $this->assertSame('/storage/post-covers/'.$post->id.'.jpg', $url);
        Http::assertNothingSent();
    }

    public function test_persist_uses_instagram_media_when_the_signed_still_is_gone(): void
    {
        Storage::fake('public');
        Http::fake([
            'https://cdn.example.com/expired.jpg' => Http::response('URL signature expired', 403),
            'https://www.instagram.com/reel/CxYz123AbCd/media/*' => Http::response(self::JPEG, 200, ['Content-Type' => 'image/jpeg']),
        ]);

        $post = $this->instagramPost('https://cdn.example.com/expired.jpg');

        $url = app(PostCoverHydrator::class)->persist($post, fetchRemote: true);

        $this->assertSame('/storage/post-covers/'.$post->id.'.jpg', $url);
        Storage::disk('public')->assertExists('post-covers/'.$post->id.'.jpg');
    }

    public function test_persist_uses_a_fresh_mapped_still_from_sync(): void
    {
        Storage::fake('public');
        Http::fake([
            'https://cdn.example.com/fresh.jpg' => Http::response(self::JPEG, 200, ['Content-Type' => 'image/jpeg']),
        ]);

        $post = $this->instagramPost('https://cdn.example.com/expired.jpg');

        $url = app(PostCoverHydrator::class)->persist($post, mapped: [
            'url' => $post->url,
            'media_url' => $post->media_url,
            'raw_payload' => [
                'displayUrl' => 'https://cdn.example.com/fresh.jpg',
            ],
        ]);

        $this->assertSame('/storage/post-covers/'.$post->id.'.jpg', $url);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://cdn.example.com/fresh.jpg');
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'expired.jpg'));
    }

    public function test_persist_stores_a_youtube_thumb_without_downloading_it(): void
    {
        Http::fake();

        $account = TrackedAccount::factory()->for(User::factory())->create([
            'platform' => Platform::Youtube,
        ]);
        $post = Post::factory()->forAccount($account)->create([
            'platform' => Platform::Youtube,
            'url' => 'https://www.youtube.com/shorts/ytShort1',
            'media_url' => 'https://cdn.example.com/yt-short1.mp4',
            'cover_url' => null,
            'raw_payload' => [],
        ]);

        $url = app(PostCoverHydrator::class)->persist($post, fetchRemote: true);

        $this->assertSame('https://i.ytimg.com/vi/ytShort1/hqdefault.jpg', $url);
        Http::assertNothingSent();
    }

    public function test_persist_reads_facebook_og_image_when_the_payload_thumb_is_gone(): void
    {
        Storage::fake('public');
        Http::fake([
            'https://cdn.example.com/fb-thumb.jpg' => Http::response('expired', 403),
            'https://www.facebook.com/*' => Http::response(
                '<meta property="og:image" content="https://cdn.example.com/fb-og.jpg" />',
                200,
                ['Content-Type' => 'text/html'],
            ),
            'https://cdn.example.com/fb-og.jpg' => Http::response(self::JPEG, 200, ['Content-Type' => 'image/jpeg']),
        ]);

        $account = TrackedAccount::factory()->for(User::factory())->create([
            'platform' => Platform::Facebook,
        ]);
        $post = Post::factory()->forAccount($account)->create([
            'platform' => Platform::Facebook,
            'url' => 'https://www.facebook.com/reel/2547789652321098/',
            'media_url' => 'https://video.xx.fbcdn.net/clip.mp4',
            'cover_url' => null,
            'raw_payload' => [
                'media' => [
                    ['thumbnail' => 'https://cdn.example.com/fb-thumb.jpg'],
                ],
            ],
        ]);

        $url = app(PostCoverHydrator::class)->persist($post, fetchRemote: true);

        $this->assertSame('/storage/post-covers/'.$post->id.'.jpg', $url);
        Storage::disk('public')->assertExists('post-covers/'.$post->id.'.jpg');
    }

    public function test_persist_replaces_a_linkedin_stream_cover_with_a_video_frame(): void
    {
        $ffmpeg = Process::timeout(10)->run(['ffmpeg', '-version']);

        if (! $ffmpeg->successful()) {
            $this->markTestSkipped('ffmpeg is not installed');
        }

        Storage::fake('public');

        $source = Storage::disk('public')->path('linkedin-media/clip.mp4');

        if (! is_dir(dirname($source))) {
            mkdir(dirname($source), 0777, true);
        }

        $made = Process::timeout(20)->run([
            'ffmpeg',
            '-y',
            '-f',
            'lavfi',
            '-i',
            'color=c=red:s=64x64:d=1',
            $source,
        ]);

        $this->assertTrue($made->successful(), $made->errorOutput());
        $this->assertTrue(is_file($source));
        Storage::disk('public')->put('linkedin-media/clip.mp4', (string) file_get_contents($source));

        $account = TrackedAccount::factory()->for(User::factory())->create([
            'platform' => Platform::LinkedIn,
        ]);
        $stream = 'https://dms.licdn.com/playlist/vid/v2/clip/file';
        $post = Post::factory()->forAccount($account)->create([
            'platform' => Platform::LinkedIn,
            'url' => 'https://www.linkedin.com/feed/update/urn:li:activity:1',
            'media_url' => '/storage/linkedin-media/clip.mp4',
            'cover_url' => $stream,
            'raw_payload' => [
                'video' => [
                    'stream_url' => $stream,
                ],
            ],
        ]);

        $url = app(PostCoverHydrator::class)->persist($post);

        $this->assertSame('/storage/post-covers/'.$post->id.'.jpg', $url);
        $bytes = Storage::disk('public')->get('post-covers/'.$post->id.'.jpg');
        $this->assertIsString($bytes);
        $this->assertStringStartsWith("\xFF\xD8", $bytes);
    }

    private function instagramPost(string $displayUrl): Post
    {
        $account = TrackedAccount::factory()->for(User::factory())->create([
            'platform' => Platform::Instagram,
        ]);

        return Post::factory()->forAccount($account)->create([
            'platform' => Platform::Instagram,
            'url' => 'https://www.instagram.com/reel/CxYz123AbCd/',
            'media_url' => 'https://cdn.example.com/ig1.mp4',
            'cover_url' => null,
            'raw_payload' => [
                'displayUrl' => $displayUrl,
            ],
        ]);
    }
}
