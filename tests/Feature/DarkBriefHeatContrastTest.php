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
        $this->assertStringContainsString('--snitch-ink: #efe6d8;', $css);
        $this->assertStringContainsString('--snitch-paper: #1c1915;', $css);
        $this->assertStringContainsString('brief', $brief);
    }
}
