<?php

namespace Tests\Feature;

use App\Enums\Platform;
use App\Models\Post;
use App\Models\TrackedAccount;
use App\Models\User;
use App\Services\Apify\Contracts\PlatformAdapter;
use App\Services\Apify\PlatformAdapterManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MirrorImagesCommandTest extends TestCase
{
    use RefreshDatabase;

    private const JPEG = "\xFF\xD8\xFF\xD9";

    public function test_dry_run_does_not_write_covers_or_avatars(): void
    {
        Storage::fake('public');
        Http::fake([
            'https://cdn.example.com/*' => Http::response(self::JPEG, 200, ['Content-Type' => 'image/jpeg']),
        ]);

        $account = TrackedAccount::factory()->for(User::factory())->create([
            'avatar' => 'https://cdn.example.com/avatar.jpg',
            'avatar_source_url' => 'https://cdn.example.com/avatar.jpg',
        ]);
        $account->socialAccount?->forceFill([
            'avatar' => 'https://cdn.example.com/avatar.jpg',
            'avatar_source_url' => 'https://cdn.example.com/avatar.jpg',
        ])->save();

        $post = Post::factory()->forAccount($account)->create([
            'platform' => Platform::Instagram,
            'cover_url' => 'https://cdn.example.com/cover.jpg',
            'raw_payload' => ['displayUrl' => 'https://cdn.example.com/cover.jpg'],
        ]);

        $exit = Artisan::call('snitch:mirror-images', [
            '--posts' => true,
            '--avatars' => true,
            '--dry-run' => true,
            '--tracked' => [$account->id],
        ]);

        $this->assertSame(0, $exit);
        $this->assertSame('https://cdn.example.com/cover.jpg', $post->fresh()?->getRawOriginal('cover_url'));
        $this->assertSame('https://cdn.example.com/avatar.jpg', $account->socialAccount?->fresh()?->avatar);
    }

    public function test_mirrors_covers_and_avatars(): void
    {
        Storage::fake('public');
        Http::fake([
            'https://cdn.example.com/*' => Http::response(self::JPEG, 200, ['Content-Type' => 'image/jpeg']),
        ]);

        $account = TrackedAccount::factory()->for(User::factory())->create([
            'avatar' => 'https://cdn.example.com/avatar.jpg',
            'avatar_source_url' => 'https://cdn.example.com/avatar.jpg',
        ]);
        $account->socialAccount?->forceFill([
            'avatar' => 'https://cdn.example.com/avatar.jpg',
            'avatar_source_url' => 'https://cdn.example.com/avatar.jpg',
        ])->save();

        $post = Post::factory()->forAccount($account)->create([
            'platform' => Platform::Instagram,
            'cover_url' => 'https://cdn.example.com/cover.jpg',
            'raw_payload' => ['displayUrl' => 'https://cdn.example.com/cover.jpg'],
        ]);

        $exit = Artisan::call('snitch:mirror-images', [
            '--posts' => true,
            '--avatars' => true,
            '--tracked' => [$account->id],
        ]);

        $this->assertSame(0, $exit);
        $this->assertSame('/storage/post-covers/'.$post->id.'.jpg', $post->fresh()?->getRawOriginal('cover_url'));
        $this->assertSame('https://cdn.example.com/cover.jpg', $post->fresh()?->cover_source_url);
        $avatar = $account->socialAccount?->fresh()?->avatar;
        $this->assertIsString($avatar);
        $this->assertStringStartsWith('/storage/avatars/', $avatar);
    }

    public function test_fetch_refreshes_dead_avatar_via_platform_adapter(): void
    {
        Storage::fake('public');
        Http::fake([
            'https://cdn.example.com/expired.jpg' => Http::response('gone', 403),
            'https://cdn.example.com/fresh-avatar.jpg' => Http::response(self::JPEG, 200, ['Content-Type' => 'image/jpeg']),
        ]);

        $account = TrackedAccount::factory()->for(User::factory())->create([
            'platform' => Platform::Instagram,
            'handle' => 'rivalbakery',
            'avatar' => 'https://cdn.example.com/expired.jpg',
            'avatar_source_url' => 'https://cdn.example.com/expired.jpg',
        ]);
        $account->socialAccount?->forceFill([
            'avatar' => 'https://cdn.example.com/expired.jpg',
            'avatar_source_url' => 'https://cdn.example.com/expired.jpg',
            'handle' => 'rivalbakery',
            'platform' => Platform::Instagram,
        ])->save();

        $adapter = \Mockery::mock(PlatformAdapter::class);
        $adapter->shouldReceive('resolveProfile')
            ->once()
            ->with('rivalbakery')
            ->andReturn([
                'external_id' => 'ig_1',
                'handle' => 'rivalbakery',
                'url' => 'https://instagram.com/rivalbakery',
                'display_name' => 'Rival Bakery',
                'avatar' => 'https://cdn.example.com/fresh-avatar.jpg',
                'followers' => 1000,
            ]);

        $manager = \Mockery::mock(PlatformAdapterManager::class);
        $manager->shouldReceive('for')
            ->once()
            ->with(\Mockery::on(fn ($platform): bool => $platform === Platform::Instagram
                || (is_string($platform) && $platform === Platform::Instagram->value)
                || ($platform instanceof Platform && $platform === Platform::Instagram)))
            ->andReturn($adapter);
        $this->app->instance(PlatformAdapterManager::class, $manager);

        $exit = Artisan::call('snitch:mirror-images', [
            '--avatars' => true,
            '--fetch' => true,
            '--tracked' => [$account->id],
        ]);

        $this->assertSame(0, $exit);
        $avatar = $account->socialAccount?->fresh()?->avatar;
        $this->assertIsString($avatar);
        $this->assertStringStartsWith('/storage/avatars/', $avatar);
        $this->assertSame('https://cdn.example.com/fresh-avatar.jpg', $account->socialAccount?->fresh()?->avatar_source_url);
    }
}
