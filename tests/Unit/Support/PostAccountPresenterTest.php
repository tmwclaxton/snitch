<?php

namespace Tests\Unit\Support;

use App\Enums\Platform;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\TrackedAccount;
use App\Models\User;
use App\Support\PostAccountPresenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PostAccountPresenterTest extends TestCase
{
    use RefreshDatabase;

    public function test_normalize_platform_falls_back_to_social_account_when_post_platform_blank(): void
    {
        $social = SocialAccount::factory()->create([
            'platform' => Platform::TikTok,
            'handle' => 'fallbackhandle',
        ]);
        $post = Post::factory()->forSocialAccount($social)->create([
            'platform' => Platform::TikTok,
        ]);

        $post->setRawAttributes([
            ...$post->getAttributes(),
            'platform' => '',
        ], sync: true);
        $post->load('socialAccount');

        PostAccountPresenter::normalizePlatform($post);

        $this->assertSame('tiktok', $post->platform?->value ?? $post->getAttributes()['platform'] ?? null);
        $this->assertSame('tiktok', PostAccountPresenter::platformValue($post));
    }

    public function test_attach_for_user_keeps_platform_on_tracked_account_payload(): void
    {
        $user = User::factory()->create();
        $account = TrackedAccount::factory()->for($user)->create([
            'platform' => Platform::Facebook,
            'handle' => 'fbpage',
        ]);
        $post = Post::factory()->forAccount($account)->create([
            'platform' => Platform::Facebook,
        ]);

        PostAccountPresenter::attachForUser([$post], $user);

        $this->assertSame('facebook', data_get($post->getAttribute('tracked_account'), 'platform'));
        $this->assertSame('facebook', $post->platform?->value);
    }
}
