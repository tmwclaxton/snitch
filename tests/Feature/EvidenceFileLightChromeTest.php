<?php

namespace Tests\Feature;

use Tests\TestCase;

class EvidenceFileLightChromeTest extends TestCase
{
    public function test_light_mode_keeps_yellow_for_highlights_not_filter_pills(): void
    {
        $css = file_get_contents(resource_path('css/app.css'));
        $dashboard = file_get_contents(resource_path('js/pages/Dashboard.vue'));
        $whatWorks = file_get_contents(resource_path('js/components/dashboard/ExecWhatWorks.vue'));
        $formatMix = file_get_contents(resource_path('js/components/dashboard/FormatMixChart.vue'));
        $phrase = file_get_contents(resource_path('js/lib/highlightPhrase.ts'));

        $this->assertNotFalse($css);
        $this->assertNotFalse($dashboard);
        $this->assertNotFalse($whatWorks);
        $this->assertNotFalse($formatMix);
        $this->assertNotFalse($phrase);

        $this->assertStringContainsString('PanelBigNumber', $dashboard);
        $this->assertStringNotContainsString('peer-checked:bg-snitch-ink', $dashboard);
        $this->assertStringContainsString('snitch-btn-spot', $dashboard);
        $this->assertStringContainsString('snitch-choice', $whatWorks);
        $this->assertStringContainsString('snitch-highlight', $whatWorks);
        $this->assertStringNotContainsString('bg-snitch-spot/40', $whatWorks);
        $this->assertStringNotContainsString('bg-snitch-spot/35', $formatMix);
        $this->assertStringContainsString('snitch-stat-accent', $formatMix);
        $this->assertStringContainsString('export function highlightKeyPhrase', $phrase);
        $this->assertStringContainsString('--sidebar-accent: #e0ddd4;', $css);
        $this->assertStringContainsString('.snitch-nav-current', $css);
        $this->assertStringContainsString('background-color: #fcd700 !important;', $css);
        $nav = file_get_contents(resource_path('js/components/NavMain.vue'));
        $this->assertNotFalse($nav);
        $this->assertStringContainsString('snitch-nav-label', $nav);
        $this->assertStringContainsString('gap-1', $nav);
    }
}
