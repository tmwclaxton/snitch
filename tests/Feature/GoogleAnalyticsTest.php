<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GoogleAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    public function test_home_omits_gtag_when_analytics_are_disabled(): void
    {
        config(['services.google.analytics_enabled' => false]);

        $this->get(route('home'))
            ->assertOk()
            ->assertDontSee('googletagmanager.com/gtag/js', false)
            ->assertDontSee('G-Y3VFH257B5', false);
    }

    public function test_home_includes_pwa_aware_gtag_when_analytics_are_enabled(): void
    {
        config([
            'services.google.analytics_id' => 'G-Y3VFH257B5',
            'services.google.analytics_enabled' => true,
        ]);

        $this->get(route('home'))
            ->assertOk()
            ->assertSee('https://www.googletagmanager.com/gtag/js?id=G-Y3VFH257B5', false)
            ->assertSee("gtag('config'", false)
            ->assertSee('send_page_view: false', false)
            ->assertSee("transport_type: 'beacon'", false)
            ->assertSee('pwa_display', false)
            ->assertSee('display-mode: standalone', false)
            ->assertSee("cookie_flags: 'SameSite=Lax;Secure'", false);
    }

    public function test_spa_tracker_sends_inertia_page_views_and_pwa_display(): void
    {
        $source = file_get_contents(resource_path('js/lib/googleAnalytics.ts'));
        $app = file_get_contents(resource_path('js/app.ts'));

        $this->assertIsString($source);
        $this->assertIsString($app);
        $this->assertStringContainsString("router.on('navigate'", $source);
        $this->assertStringContainsString('pwa_display', $source);
        $this->assertStringContainsString('transport_type', file_get_contents(resource_path('views/app.blade.php')));
        $this->assertStringContainsString('initializeGoogleAnalytics', $app);
        $this->assertStringContainsString("typeof window !== 'undefined'", $app);
        $this->assertStringContainsString('appinstalled', $source);
        $this->assertStringContainsString('display-mode: standalone', $source);
        $this->assertStringContainsString('nav.standalone', $source);
    }

    public function test_cookie_notice_discloses_google_analytics(): void
    {
        $cookies = file_get_contents(resource_path('js/pages/marketing/Cookies.vue'));

        $this->assertIsString($cookies);
        $this->assertStringContainsString('Google Analytics 4', $cookies);
        $this->assertStringContainsString('G-Y3VFH257B5', $cookies);
        $this->assertStringNotContainsString(
            'does not ship third-party marketing analytics cookies',
            $cookies,
        );
    }
}
