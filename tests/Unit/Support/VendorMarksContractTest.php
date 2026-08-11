<?php

namespace Tests\Unit\Support;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class VendorMarksContractTest extends TestCase
{
    #[Test]
    public function vendor_helper_exposes_labels_icons_and_distinct_chart_colours(): void
    {
        $source = file_get_contents(base_path('resources/js/lib/vendors.ts'));

        $this->assertIsString($source);
        $this->assertStringContainsString('export function vendorLabel', $source);
        $this->assertStringContainsString('export function vendorIconSrc', $source);
        $this->assertStringContainsString('/images/vendors/', $source);

        foreach (['apify', 'nanogpt', 'firecrawl', 'tikhub', 'snitch'] as $vendor) {
            $this->assertStringContainsString("{$vendor}:", $source);
        }

        $this->assertFileExists(public_path('images/vendors/apify.svg'));
        $this->assertFileExists(public_path('images/vendors/nanogpt.svg'));
        $this->assertFileExists(public_path('images/vendors/firecrawl.svg'));
        $this->assertFileExists(public_path('images/vendors/tikhub.png'));
        $this->assertFileExists(public_path('images/brand/mascot-mark.png'));
        $this->assertStringContainsString("tikhub: 'tikhub.png'", $source);
        $this->assertStringContainsString("snitch: '/images/brand/mascot-mark.png'", $source);

        // Official / brand-sourced marks should not be the tiny handmade placeholders.
        $this->assertGreaterThan(500, filesize(public_path('images/vendors/apify.svg')));
        $this->assertGreaterThan(500, filesize(public_path('images/vendors/nanogpt.svg')));
        $this->assertGreaterThan(500, filesize(public_path('images/vendors/firecrawl.svg')));
        $this->assertGreaterThan(1000, filesize(public_path('images/vendors/tikhub.png')));
        $this->assertGreaterThan(1000, filesize(public_path('images/brand/mascot-mark.png')));

        foreach (['bonus', 'topup'] as $vendor) {
            $this->assertFileExists(public_path("images/vendors/{$vendor}.svg"));
        }

        $fills = [];
        preg_match_all("/(apify|nanogpt|firecrawl|tikhub|snitch): '(fill-[^']+)'/", $source, $matches);
        foreach ($matches[1] as $index => $vendor) {
            $fills[$vendor] = $matches[2][$index];
        }

        $this->assertCount(5, $fills);
        $this->assertCount(5, array_unique(array_values($fills)));
        $this->assertSame('fill-snitch-stipple-spot', $fills['snitch']);
        $this->assertStringNotContainsString('VENDOR_CHART_SWATCH', $source);
    }

    #[Test]
    public function spend_chart_legend_uses_vendor_logos_not_colour_dots(): void
    {
        $source = file_get_contents(base_path('resources/js/components/billing/VendorSpendStackedChart.vue'));
        $css = file_get_contents(base_path('resources/css/app.css'));

        $this->assertIsString($source);
        $this->assertIsString($css);
        $this->assertStringContainsString('snitch-vendor-legend-mark', $source);
        $this->assertStringContainsString('vendorIconSrc', $source);
        $this->assertStringContainsString('alt=""', $source);
        $this->assertStringContainsString('aria-hidden="true"', $source);
        $this->assertStringContainsString('variant="dots"', $source);
        $this->assertStringContainsString('snitch-vendor-spend-tip', $source);
        $this->assertStringNotContainsString('swatchClass', $source);
        $this->assertStringNotContainsString(':image-src', $source);
        $this->assertStringContainsString('.snitch-vendor-legend-mark', $css);
        $this->assertStringContainsString('.snitch-vendor-spend-tip', $css);
    }

    #[Test]
    public function billing_pages_render_vendor_icons_beside_names(): void
    {
        $index = file_get_contents(base_path('resources/js/pages/billing/Index.vue'));
        $charges = file_get_contents(base_path('resources/js/pages/billing/Charges.vue'));

        $this->assertIsString($index);
        $this->assertIsString($charges);

        foreach ([$index, $charges] as $source) {
            $this->assertStringContainsString('vendorIconSrc', $source);
            $this->assertStringContainsString('vendorLabel', $source);
            $this->assertStringContainsString('snitch-platform-logo', $source);
        }
    }
}
