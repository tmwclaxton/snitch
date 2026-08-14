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
            ->assertDontSee('G-Y3VFH257B5', false)
            ->assertDontSee('AW-18219075665', false);
    }

    public function test_home_includes_pwa_aware_gtag_when_analytics_are_enabled(): void
    {
        config([
            'services.google.analytics_id' => 'G-Y3VFH257B5',
            'services.google.analytics_enabled' => true,
        ]);

        $html = $this->get(route('home'))->assertOk()->getContent();

        $this->assertIsString($html);
        $this->assertStringContainsString('<!-- Google tag (gtag.js) -->', $html);
        $this->assertStringContainsString('https://www.googletagmanager.com/gtag/js?id=G-Y3VFH257B5', $html);
        $this->assertStringContainsString("gtag('config', 'G-Y3VFH257B5');", $html);
        $this->assertStringContainsString("gtag('config', 'AW-18219075665');", $html);
        $this->assertStringContainsString('AW-18219075665/xFpFCIDTldQcENGQxO9D', $html);
        $this->assertStringContainsString('function gtag_report_conversion', $html);
        $this->assertLessThan(
            1200,
            strpos($html, "gtag('config', 'G-Y3VFH257B5');") ?: PHP_INT_MAX,
            'Google tag config must sit at the top of head so GA can detect it',
        );
        $this->assertStringContainsString('pwa_display', $html);
        $this->assertStringContainsString('display-mode: standalone', $html);
        $this->assertStringContainsString("transport_type: 'beacon'", $html);
    }

    public function test_spa_tracker_sends_inertia_page_views_and_pwa_display(): void
    {
        $source = file_get_contents(resource_path('js/lib/googleAnalytics.ts'));
        $app = file_get_contents(resource_path('js/app.ts'));

        $this->assertIsString($source);
        $this->assertIsString($app);
        $this->assertStringContainsString("router.on('navigate'", $source);
        $this->assertStringContainsString('pwa_display', $source);
        $this->assertStringContainsString("gtag('config', '{{ \$gaId }}');", file_get_contents(resource_path('views/app.blade.php')));
        $this->assertStringContainsString('__SNITCH_GA_EVENTS__', $source);
        $this->assertStringContainsString('gtag_report_conversion', $source);
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

    public function test_auth_and_billing_queue_recommended_ga_events(): void
    {
        $auth = file_get_contents(base_path('routes/auth.php'));
        $checkout = file_get_contents(base_path('app/Services/Billing/StripeCheckoutSyncService.php'));

        $this->assertIsString($auth);
        $this->assertIsString($checkout);
        $this->assertStringContainsString('GoogleAnalytics::queueEvent($gaAuthEvent', $auth);
        $this->assertStringContainsString("'sign_up'", $auth);
        $this->assertStringContainsString("'login'", $auth);
        $this->assertStringContainsString('GoogleAnalytics::queuePurchase($session)', $checkout);
    }

    public function test_production_env_example_enables_google_analytics(): void
    {
        $example = file_get_contents(base_path('deploy/env.production.example'));

        $this->assertIsString($example);
        $this->assertStringContainsString('GOOGLE_ANALYTICS_ID=G-Y3VFH257B5', $example);
        $this->assertStringContainsString('GOOGLE_ANALYTICS_ENABLED=true', $example);
        $this->assertStringContainsString('GOOGLE_ADS_ID=AW-18219075665', $example);
        $this->assertStringContainsString(
            'GOOGLE_ADS_SIGNUP_SEND_TO=AW-18219075665/xFpFCIDTldQcENGQxO9D',
            $example,
        );
    }
}
