<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class DarkBriefHeatContrastTest extends TestCase
{
    #[Test]
    public function dark_brief_heat_axis_uses_ink_fog_type_not_surface_fog(): void
    {
        $css = file_get_contents(resource_path('css/app.css'));
        $brief = file_get_contents(resource_path('js/pages/brief/Index.vue'));

        $this->assertIsString($css);
        $this->assertIsString($brief);
        $this->assertStringContainsString('.dark .snitch-brief-heat-cell', $css);
        $this->assertStringContainsString('#1f1f23', $css);
        $this->assertStringContainsString('#3a3a40', $css);
        $this->assertStringContainsString('.dark .snitch-brief-heat-axis', $css);
        $this->assertStringContainsString('font-size: 0.875rem', $css);
        $this->assertStringContainsString(
            'color: color-mix(in oklab, var(--snitch-ink) 82%, transparent)',
            $css,
        );
        $this->assertStringNotContainsString(
            'var(--snitch-fog, var(--snitch-ink)) 78%',
            $css,
        );
        $this->assertStringContainsString('snitch-brief-idea-visual', $brief);
        $this->assertStringContainsString('.dark .snitch-brief-idea-visual', $css);
        $this->assertStringContainsString('snitch-brief-heat-axis', $brief);
        $this->assertStringContainsString('--snitch-brief-heat-empty', $brief);
    }
}
