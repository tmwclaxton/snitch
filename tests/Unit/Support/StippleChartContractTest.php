<?php

namespace Tests\Unit\Support;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class StippleChartContractTest extends TestCase
{
    #[Test]
    public function stipple_helper_exports_dot_hex_and_logo_builders(): void
    {
        $source = file_get_contents(base_path('resources/js/lib/stipple.ts'));

        $this->assertIsString($source);
        $this->assertStringContainsString('export function buildStippleMarks', $source);
        $this->assertStringContainsString('export function buildLogoMarks', $source);
        $this->assertStringContainsString("variant === 'hexes'", $source);
        $this->assertStringContainsString('buildDotMarks', $source);
        $this->assertStringContainsString('buildHexMarks', $source);
        $this->assertStringContainsString("kind: 'logo'", $source);
    }

    #[Test]
    public function dashboard_volume_and_time_charts_use_dot_stipple_bars(): void
    {
        $weekly = file_get_contents(base_path('resources/js/components/dashboard/WeeklyVolumeChart.vue'));
        $timeOfDay = file_get_contents(base_path('resources/js/components/dashboard/TimeOfDayChart.vue'));

        $this->assertIsString($weekly);
        $this->assertIsString($timeOfDay);

        foreach ([$weekly, $timeOfDay] as $source) {
            $this->assertStringContainsString('<StippleBar', $source);
            $this->assertStringContainsString('variant="dots"', $source);
            $this->assertStringContainsString('yTicks', $source);
            $this->assertStringNotContainsString('<rect', $source);
        }
    }

    #[Test]
    public function platform_split_chart_uses_larger_dot_stipple_bars(): void
    {
        $source = file_get_contents(base_path('resources/js/components/PlatformStippleTrack.vue'));

        $this->assertIsString($source);
        $this->assertStringContainsString('<StippleBar', $source);
        $this->assertStringContainsString('variant="dots"', $source);
        $this->assertStringContainsString(':step="4.6"', $source);
        $this->assertStringContainsString(':radius="1.55"', $source);
    }

    #[Test]
    public function analytics_daily_metric_chart_uses_dot_stipple_bars(): void
    {
        $source = file_get_contents(base_path('resources/js/components/analytics/DailyMetricChart.vue'));

        $this->assertIsString($source);
        $this->assertStringContainsString('<StippleBar', $source);
        $this->assertStringContainsString('variant="dots"', $source);
        $this->assertStringNotContainsString('<rect', $source);
    }

    #[Test]
    public function billing_vendor_spend_chart_uses_dense_logo_lattice_bars(): void
    {
        $source = file_get_contents(base_path('resources/js/components/billing/VendorSpendStackedChart.vue'));
        $bar = file_get_contents(base_path('resources/js/components/dashboard/StippleBar.vue'));
        $vendors = file_get_contents(base_path('resources/js/lib/vendors.ts'));

        $this->assertIsString($source);
        $this->assertIsString($bar);
        $this->assertIsString($vendors);
        $this->assertStringContainsString('<StippleBar', $source);
        $this->assertStringContainsString(':image-src="segment.iconSrc"', $source);
        $this->assertStringContainsString('logoSize', $source);
        $this->assertStringContainsString('logoStep', $source);
        $this->assertStringContainsString(':animate="false"', $source);
        $this->assertStringContainsString('snitch-vendor-legend-mark', $source);
        $this->assertStringContainsString('vendorIconSrc', $source);
        $this->assertStringNotContainsString('variant="dots"', $source);
        $this->assertStringNotContainsString('VENDOR_CHART_FILL', $source);
        $this->assertStringNotContainsString('VENDOR_CHART_SWATCH', $source);
        $this->assertStringNotContainsString('swatchClass', $source);
        $this->assertStringContainsString('imageSrc', $bar);
        $this->assertStringContainsString('buildLogoMarks', $bar);
        $this->assertStringContainsString('snitch-vendor-chart-logo', $bar);
        $this->assertStringContainsString("nanogpt: 'fill-snitch-stipple-spot'", $vendors);
        $this->assertStringNotContainsString('VENDOR_CHART_SWATCH', $vendors);
        $this->assertStringNotContainsString('<rect', $source);
    }

    #[Test]
    public function analytics_page_platform_mix_uses_stipple_track(): void
    {
        $source = file_get_contents(base_path('resources/js/pages/marketing/Analytics.vue'));

        $this->assertIsString($source);
        $this->assertStringContainsString('<PlatformStippleTrack', $source);
        $this->assertStringNotContainsString('bg-snitch-ink/10', $source);
    }

    #[Test]
    public function stipple_bar_reveals_marks_one_by_one(): void
    {
        $source = file_get_contents(base_path('resources/js/components/dashboard/StippleBar.vue'));
        $css = file_get_contents(base_path('resources/css/app.css'));

        $this->assertIsString($source);
        $this->assertIsString($css);
        $this->assertStringContainsString('visibleCount', $source);
        $this->assertStringContainsString('stepMs', $source);
        $this->assertStringContainsString('index < visibleCount', $source);
        $this->assertStringContainsString('@keyframes snitch-stipple-pop', $css);
    }
}
