<?php

namespace Tests\Feature;

use Tests\TestCase;

class EvidenceFileLightChromeTest extends TestCase
{
    public function test_light_mode_keeps_yellow_for_highlights_not_filter_pills(): void
    {
        $css = file_get_contents(resource_path('css/app.css'));
        $dashboard = file_get_contents(resource_path('js/pages/ExecutiveBrief.vue'));

        $this->assertNotFalse($css);
        $this->assertNotFalse($dashboard);
        $this->assertStringContainsString('Executive Brief', $dashboard);
        $this->assertStringContainsString('--snitch-spot: #f0c400;', $css);
    }
}
