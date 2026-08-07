<?php

namespace Tests\Unit\Support;

use App\Enums\Platform;
use App\Support\PlatformEmbed;
use PHPUnit\Framework\TestCase;

class PlatformEmbedTest extends TestCase
{
    public function test_resolves_tiktok_player_embed(): void
    {
        $embed = PlatformEmbed::resolve(
            Platform::TikTok,
            'https://www.tiktok.com/@scout2015/video/6718335390845095173',
        );

        $this->assertNotNull($embed);
        $this->assertSame('tiktok', $embed['provider']);
        $this->assertStringContainsString(
            'https://www.tiktok.com/player/v1/6718335390845095173',
            $embed['src'],
        );
    }

    public function test_resolves_instagram_reel_and_post_embeds(): void
    {
        $post = PlatformEmbed::resolve(
            Platform::Instagram,
            'https://www.instagram.com/p/fA9uwTtkSN/',
        );
        $reel = PlatformEmbed::resolve(
            Platform::Instagram,
            'https://www.instagram.com/reel/CxYz123AbCd/',
        );
        $compact = PlatformEmbed::resolve(
            Platform::Instagram,
            'https://www.instagram.com/p/fA9uwTtkSN/',
            compact: true,
        );

        $this->assertSame(
            'https://www.instagram.com/p/fA9uwTtkSN/embed/captioned/',
            $post['src'] ?? null,
        );
        $this->assertSame(
            'https://www.instagram.com/reel/CxYz123AbCd/embed/captioned/',
            $reel['src'] ?? null,
        );
        $this->assertSame(
            'https://www.instagram.com/p/fA9uwTtkSN/embed/',
            $compact['src'] ?? null,
        );
    }

    public function test_resolves_facebook_post_and_video_plugins(): void
    {
        $post = PlatformEmbed::resolve(
            Platform::Facebook,
            'https://www.facebook.com/username/posts/1234567890',
        );
        $video = PlatformEmbed::resolve(
            Platform::Facebook,
            'https://www.facebook.com/username/videos/9876543210/',
        );

        $this->assertNotNull($post);
        $this->assertStringContainsString('plugins/post.php', $post['src']);
        $this->assertStringContainsString(rawurlencode('https://www.facebook.com/username/posts/1234567890'), $post['src']);

        $this->assertNotNull($video);
        $this->assertStringContainsString('plugins/video.php', $video['src']);
    }

    public function test_resolves_linkedin_activity_from_posts_url(): void
    {
        $embed = PlatformEmbed::resolve(
            Platform::LinkedIn,
            'https://www.linkedin.com/posts/jane-doe_activity-7123456789012345678-abcd',
        );

        $this->assertNotNull($embed);
        $this->assertSame(
            'https://www.linkedin.com/embed/feed/update/urn:li:activity:7123456789012345678',
            $embed['src'],
        );
    }

    public function test_returns_null_when_url_missing_or_unrecognized(): void
    {
        $this->assertNull(PlatformEmbed::resolve(Platform::TikTok, null));
        $this->assertNull(PlatformEmbed::resolve(Platform::TikTok, ''));
        $this->assertNull(PlatformEmbed::resolve(Platform::TikTok, 'https://example.com/not-a-tiktok'));
        $this->assertNull(PlatformEmbed::resolve('unknown', 'https://www.tiktok.com/@x/video/1'));
    }
}
