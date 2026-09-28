<?php

namespace Tests\Unit\Support;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PlatformsLibContractTest extends TestCase
{
    #[Test]
    public function product_platform_helpers_use_real_network_names_and_icons(): void
    {
        $source = file_get_contents(base_path('resources/js/lib/platforms.ts'));

        $this->assertIsString($source);
        $this->assertStringContainsString("instagram: 'Instagram'", $source);
        $this->assertStringContainsString("tiktok: 'TikTok'", $source);
        $this->assertStringContainsString("facebook: 'Facebook'", $source);
        $this->assertStringContainsString("linkedin: 'LinkedIn'", $source);
        $this->assertStringContainsString("youtube: 'YouTube'", $source);
        $this->assertStringContainsString('return platformLabel(platform)', $source);
        $this->assertStringContainsString('`/images/platforms/${key}.svg`', $source);
        $this->assertStringContainsString('Open on ${label}', $source);
        $this->assertStringNotContainsString(
            "if (!isInstagramPlatform(platform)) {\n        return '/images/platforms/instagram.svg';",
            $source,
        );

        foreach (['instagram', 'tiktok', 'facebook', 'linkedin', 'youtube'] as $platform) {
            $this->assertFileExists(public_path("images/platforms/{$platform}.svg"));
        }
    }

    #[Test]
    public function contact_cell_cover_filter_does_not_screen_blend_pale_images(): void
    {
        $css = file_get_contents(base_path('resources/css/app.css'));

        $this->assertIsString($css);
        $this->assertMatchesRegularExpression(
            '/\.snitch-platform-embed-fallback-img\s*\{[^}]*mix-blend-mode:\s*normal/s',
            $css,
        );
        $this->assertDoesNotMatchRegularExpression(
            '/\.snitch-platform-embed-fallback-img\s*\{[^}]*mix-blend-mode:\s*screen/s',
            $css,
        );
    }
}
