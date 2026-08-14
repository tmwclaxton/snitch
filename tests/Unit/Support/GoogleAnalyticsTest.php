<?php

namespace Tests\Unit\Support;

use App\Support\GoogleAnalytics;
use Tests\TestCase;

class GoogleAnalyticsTest extends TestCase
{
    public function test_is_disabled_in_the_testing_environment_by_default(): void
    {
        config(['services.google.analytics_enabled' => null]);

        $this->assertSame('G-Y3VFH257B5', GoogleAnalytics::measurementId());
        $this->assertFalse(GoogleAnalytics::enabled());
    }

    public function test_defaults_to_enabled_in_production_when_flag_is_unset(): void
    {
        $this->app['env'] = 'production';

        config([
            'services.google.analytics_id' => 'G-Y3VFH257B5',
            'services.google.analytics_enabled' => null,
        ]);

        $this->assertTrue(GoogleAnalytics::enabled());
    }

    public function test_is_disabled_when_the_measurement_id_is_blank(): void
    {
        config([
            'services.google.analytics_id' => '',
            'services.google.analytics_enabled' => true,
        ]);

        $this->assertNull(GoogleAnalytics::measurementId());
        $this->assertFalse(GoogleAnalytics::enabled());
    }

    public function test_is_enabled_when_id_and_flag_are_set(): void
    {
        config([
            'services.google.analytics_id' => 'G-Y3VFH257B5',
            'services.google.analytics_enabled' => 'true',
        ]);

        $this->assertTrue(GoogleAnalytics::enabled());
        $this->assertSame('G-Y3VFH257B5', GoogleAnalytics::measurementId());
    }

    public function test_ads_signup_conversion_ids_are_configured(): void
    {
        $this->assertSame('AW-18219075665', GoogleAnalytics::adsId());
        $this->assertSame(
            'AW-18219075665/xFpFCIDTldQcENGQxO9D',
            GoogleAnalytics::adsSignupSendTo(),
        );
    }

    public function test_queued_events_are_taken_once(): void
    {
        GoogleAnalytics::queueEvent('login', ['method' => 'WorkOS']);
        GoogleAnalytics::queueEvent('sign_up', ['method' => 'WorkOS']);

        $first = GoogleAnalytics::takeEvents();
        $second = GoogleAnalytics::takeEvents();

        $this->assertSame(['login', 'sign_up'], array_column($first, 'name'));
        $this->assertSame($first, $second);
    }

    public function test_purchase_queue_is_idempotent_per_checkout_session(): void
    {
        $session = [
            'id' => 'cs_ga_once',
            'amount_total' => 1900,
            'currency' => 'gbp',
            'metadata' => ['snitch_product' => 'platform'],
        ];

        GoogleAnalytics::queuePurchase($session);
        GoogleAnalytics::queuePurchase($session);

        $events = GoogleAnalytics::takeEvents();

        $this->assertCount(1, $events);
        $this->assertSame('purchase', $events[0]['name']);
        $this->assertSame(19.0, $events[0]['params']['value']);
    }
}
