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
}
