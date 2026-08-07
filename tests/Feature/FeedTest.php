<?php

namespace Tests\Feature;

use App\Enums\Platform;
use App\Models\BrandProfile;
use App\Models\Post;
use App\Models\PostAnalysis;
use App\Models\TrackedAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class FeedTest extends TestCase
{
    use RefreshDatabase;

    public function test_feed_lists_only_owner_posts(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        BrandProfile::factory()->for($user)->create();

        $account = TrackedAccount::factory()->for($user)->create();
        $post = Post::factory()->forAccount($account)->create();
        PostAnalysis::factory()->for($post)->create();

        $otherAccount = TrackedAccount::factory()->for($other)->create();
        Post::factory()->forAccount($otherAccount)->create();

        $this->actingAs($user)
            ->get(route('feed.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('feed/Index')
                ->has('posts.data', 1)
                ->where('posts.data.0.id', $post->id)
            );
    }

    public function test_post_detail_is_authorized(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        BrandProfile::factory()->for($user)->create();
        BrandProfile::factory()->for($other)->create();

        $account = TrackedAccount::factory()->for($user)->create();
        $post = Post::factory()->forAccount($account)->create();

        $this->actingAs($user)
            ->get(route('feed.show', $post))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('feed/Show'));

        $this->actingAs($other)
            ->get(route('feed.show', $post))
            ->assertForbidden();
    }

    public function test_feed_includes_platform_embed_payload(): void
    {
        $user = User::factory()->create();
        BrandProfile::factory()->for($user)->create();

        $account = TrackedAccount::factory()->for($user)->create([
            'platform' => Platform::TikTok,
        ]);
        $post = Post::factory()->forAccount($account)->create([
            'platform' => Platform::TikTok,
            'url' => 'https://www.tiktok.com/@demo/video/6718335390845095173',
        ]);

        $this->actingAs($user)
            ->get(route('feed.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('feed/Index')
                ->where('posts.data.0.id', $post->id)
                ->where('posts.data.0.embed.provider', 'tiktok')
                ->where(
                    'posts.data.0.embed.src',
                    'https://www.tiktok.com/player/v1/6718335390845095173?music_info=0&description=0&autoplay=0',
                )
            );
    }

    public function test_post_detail_includes_platform_embed_or_null_fallback(): void
    {
        $user = User::factory()->create();
        BrandProfile::factory()->for($user)->create();

        $account = TrackedAccount::factory()->for($user)->create([
            'platform' => Platform::Instagram,
        ]);
        $withEmbed = Post::factory()->forAccount($account)->create([
            'platform' => Platform::Instagram,
            'url' => 'https://www.instagram.com/reel/CxYz123AbCd/',
        ]);
        $withoutEmbed = Post::factory()->forAccount($account)->create([
            'platform' => Platform::Instagram,
            'url' => 'https://example.com/not-instagram',
        ]);

        $this->actingAs($user)
            ->get(route('feed.show', $withEmbed))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('feed/Show')
                ->where('post.embed.provider', 'instagram')
                ->where(
                    'post.embed.src',
                    'https://www.instagram.com/reel/CxYz123AbCd/embed/captioned/',
                )
            );

        $this->actingAs($user)
            ->get(route('feed.show', $withoutEmbed))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('feed/Show')
                ->where('post.embed', null)
            );
    }
}
