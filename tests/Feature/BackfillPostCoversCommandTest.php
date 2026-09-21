<?php

namespace Tests\Feature;

use App\Enums\Platform;
use App\Models\Post;
use App\Models\TrackedAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class BackfillPostCoversCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_persists_covers_from_existing_payloads(): void
    {
        Storage::fake('public');
        Http::fake([
            'https://cdn.example.com/ig1.jpg' => Http::response("\xFF\xD8\xFF\xD9", 200, ['Content-Type' => 'image/jpeg']),
        ]);

        $account = TrackedAccount::factory()->for(User::factory())->create();
        $post = Post::factory()->forAccount($account)->create([
            'platform' => Platform::Instagram,
            'media_url' => 'https://cdn.example.com/ig1.mp4',
            'cover_url' => null,
            'raw_payload' => [
                'displayUrl' => 'https://cdn.example.com/ig1.jpg',
            ],
        ]);

        $exit = Artisan::call('snitch:backfill-covers');

        $this->assertSame(0, $exit);
        $post->refresh();
        $this->assertSame('/storage/post-covers/'.$post->id.'.jpg', $post->getRawOriginal('cover_url'));
        Storage::disk('public')->assertExists('post-covers/'.$post->id.'.jpg');
    }

    public function test_rewrites_a_stored_signed_cdn_url(): void
    {
        Storage::fake('public');
        Http::fake([
            'https://scontent.cdninstagram.com/v/expired.jpg*' => Http::response("\xFF\xD8\xFF\xD9", 200, ['Content-Type' => 'image/jpeg']),
        ]);

        $account = TrackedAccount::factory()->for(User::factory())->create();
        $signed = 'https://scontent.cdninstagram.com/v/expired.jpg?oe=DEAD';
        $post = Post::factory()->forAccount($account)->create([
            'platform' => Platform::Instagram,
            'cover_url' => $signed,
            'raw_payload' => [
                'displayUrl' => $signed,
            ],
        ]);

        $exit = Artisan::call('snitch:backfill-covers');

        $this->assertSame(0, $exit);
        $post->refresh();
        $this->assertSame('/storage/post-covers/'.$post->id.'.jpg', $post->getRawOriginal('cover_url'));
    }

    public function test_dry_run_does_not_write(): void
    {
        $account = TrackedAccount::factory()->for(User::factory())->create();
        $post = Post::factory()->forAccount($account)->create([
            'platform' => Platform::Instagram,
            'cover_url' => null,
            'raw_payload' => [
                'displayUrl' => 'https://cdn.example.com/ig1.jpg',
            ],
        ]);

        $exit = Artisan::call('snitch:backfill-covers', ['--dry-run' => true]);

        $this->assertSame(0, $exit);
        $post->refresh();
        $this->assertNull($post->getRawOriginal('cover_url'));
    }

    public function test_fetch_uses_tiktok_oembed_when_payload_has_no_still(): void
    {
        Storage::fake('public');
        Http::fake([
            'https://www.tiktok.com/oembed*' => Http::response([
                'thumbnail_url' => 'https://cdn.example.com/tt-cover.jpg',
            ], 200),
            'https://cdn.example.com/tt-cover.jpg' => Http::response("\xFF\xD8\xFF\xD9", 200, ['Content-Type' => 'image/jpeg']),
        ]);

        $account = TrackedAccount::factory()->for(User::factory())->create([
            'platform' => Platform::TikTok,
        ]);
        $post = Post::factory()->forAccount($account)->create([
            'platform' => Platform::TikTok,
            'url' => 'https://www.tiktok.com/@rivalbakery/video/1',
            'media_url' => 'https://cdn.example.com/tt1.mp4',
            'cover_url' => null,
            'raw_payload' => [],
        ]);

        $exit = Artisan::call('snitch:backfill-covers', ['--fetch' => true]);

        $this->assertSame(0, $exit);
        $post->refresh();
        $this->assertSame('/storage/post-covers/'.$post->id.'.jpg', $post->getRawOriginal('cover_url'));
        Http::assertSent(fn ($request) => str_contains($request->url(), 'tiktok.com/oembed'));
    }
}
