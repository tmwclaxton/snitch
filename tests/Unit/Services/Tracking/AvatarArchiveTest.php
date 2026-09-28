<?php

namespace Tests\Unit\Services\Tracking;

use App\Models\SocialAccount;
use App\Models\TrackedAccount;
use App\Models\User;
use App\Services\Tracking\AvatarArchive;
use App\Services\Tracking\AvatarMirror;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AvatarArchiveTest extends TestCase
{
    use RefreshDatabase;

    private const JPEG = "\xFF\xD8\xFF\xD9";

    public function test_store_downloads_avatar_onto_public_disk(): void
    {
        Storage::fake('public');
        Http::fake([
            'https://cdn.example.com/avatar.jpg' => Http::response(self::JPEG, 200, ['Content-Type' => 'image/jpeg']),
        ]);

        $social = SocialAccount::factory()->create();
        $url = app(AvatarArchive::class)->store($social->id, 'https://cdn.example.com/avatar.jpg');

        $this->assertNotNull($url);
        $this->assertMatchesRegularExpression('#^/storage/avatars/'.$social->id.'-[a-f0-9]{10}\.jpg$#', $url);
        Storage::disk('public')->assertExists(substr($url, strlen('/storage/')));
    }

    public function test_store_returns_null_on_403(): void
    {
        Storage::fake('public');
        Http::fake([
            'https://cdn.example.com/avatar.jpg' => Http::response('gone', 403),
        ]);

        $social = SocialAccount::factory()->create();
        $url = app(AvatarArchive::class)->store($social->id, 'https://cdn.example.com/avatar.jpg');

        $this->assertNull($url);
    }

    public function test_store_returns_null_for_non_image(): void
    {
        Storage::fake('public');
        Http::fake([
            'https://cdn.example.com/avatar.bin' => Http::response('not-image', 200, ['Content-Type' => 'application/octet-stream']),
        ]);

        $social = SocialAccount::factory()->create();
        $url = app(AvatarArchive::class)->store($social->id, 'https://cdn.example.com/avatar.bin');

        $this->assertNull($url);
    }

    public function test_mirror_is_idempotent_when_source_unchanged(): void
    {
        Storage::fake('public');
        Http::fake([
            'https://cdn.example.com/avatar.jpg' => Http::response(self::JPEG, 200, ['Content-Type' => 'image/jpeg']),
        ]);

        $user = User::factory()->create();
        $account = TrackedAccount::factory()->for($user)->create([
            'avatar' => null,
        ]);
        $social = $account->socialAccount;
        $this->assertNotNull($social);

        $mirror = app(AvatarMirror::class);
        $mirror->apply($account, $social, 'https://cdn.example.com/avatar.jpg');
        $account->refresh();
        $social->refresh();
        $local = $social->avatar;

        Http::fake();
        $mirror->apply($account->fresh(), $social->fresh(), 'https://cdn.example.com/avatar.jpg');

        $this->assertSame($local, $social->fresh()->avatar);
        Http::assertNothingSent();
    }

    public function test_mirror_failure_keeps_existing_local_avatar(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('avatars/1-aaaaaaaaaa.jpg', self::JPEG);

        $user = User::factory()->create();
        $account = TrackedAccount::factory()->for($user)->create([
            'avatar' => '/storage/avatars/1-aaaaaaaaaa.jpg',
            'avatar_source_url' => 'https://cdn.example.com/old.jpg',
        ]);
        $social = $account->socialAccount;
        $this->assertNotNull($social);
        $social->forceFill([
            'avatar' => '/storage/avatars/1-aaaaaaaaaa.jpg',
            'avatar_source_url' => 'https://cdn.example.com/old.jpg',
        ])->save();
        Storage::disk('public')->put('avatars/'.$social->id.'-aaaaaaaaaa.jpg', self::JPEG);
        $local = '/storage/avatars/'.$social->id.'-aaaaaaaaaa.jpg';
        $social->forceFill(['avatar' => $local])->save();
        $account->forceFill(['avatar' => $local])->save();

        Http::fake([
            'https://cdn.example.com/fresh.jpg' => Http::response('gone', 403),
        ]);

        app(AvatarMirror::class)->apply($account->fresh(), $social->fresh(), 'https://cdn.example.com/fresh.jpg');

        $social->refresh();
        $account->refresh();
        $this->assertSame($local, $social->avatar);
        $this->assertSame($local, $account->avatar);
        $this->assertSame('https://cdn.example.com/fresh.jpg', $social->avatar_source_url);
    }
}
