<?php

namespace Tests\Feature;

use Tests\TestCase;

class CautionTapeAppChromeTest extends TestCase
{
    public function test_logged_in_shell_follows_appearance_setting(): void
    {
        $layout = file_get_contents(resource_path('js/layouts/app/AppSidebarLayout.vue'));
        $appearance = file_get_contents(app_path('Http/Middleware/HandleAppearance.php'));
        $blade = file_get_contents(resource_path('views/app.blade.php'));
        $theme = file_get_contents(resource_path('js/composables/useAppearance.ts'));
        $charts = file_get_contents(resource_path('js/lib/snitchTheme.ts'));
        $sidebar = file_get_contents(resource_path('js/components/AppSidebar.vue'));
        $header = file_get_contents(resource_path('js/components/AppSidebarHeader.vue'));
        $routes = file_get_contents(base_path('routes/settings.php'));
        $chat = file_get_contents(resource_path('js/pages/chat/Index.vue'));

        $this->assertNotFalse($layout);
        $this->assertNotFalse($appearance);
        $this->assertNotFalse($blade);
        $this->assertNotFalse($theme);
        $this->assertNotFalse($charts);
        $this->assertNotFalse($sidebar);
        $this->assertNotFalse($header);
        $this->assertNotFalse($routes);
        $this->assertNotFalse($chat);

        $this->assertStringNotContainsString('snitch-app-night', $layout);
        $this->assertStringContainsString("View::share('appNight', false)", $appearance);
        $this->assertStringNotContainsString('data-app-night=', $blade);
        $this->assertStringNotContainsString('appNight', $theme);
        $this->assertStringContainsString("classList.contains('dark')", $charts);
        $this->assertStringContainsString('bg-sidebar', $sidebar);
        $this->assertStringNotContainsString('bg-black', $sidebar);
        $this->assertStringContainsString('bg-snitch-paper', $header);
        $this->assertStringContainsString('border-snitch-ink/10', $header);
        $this->assertStringContainsString('AppearanceController', $routes);
        $this->assertStringContainsString('bg-snitch-paper', $chat);
        $this->assertStringContainsString('bg-snitch-lift', $chat);
        $this->assertStringNotContainsString('bg-[#0E0E10]', $chat);
    }

    public function test_caution_tape_app_tokens_and_bands_are_wired(): void
    {
        $css = file_get_contents(resource_path('css/app.css'));
        $settings = file_get_contents(resource_path('js/layouts/settings/Layout.vue'));

        $this->assertNotFalse($css);
        $this->assertNotFalse($settings);

        $this->assertStringContainsString('html:has(.snitch-public-shell)', $css);
        $this->assertStringContainsString('.snitch-caution-band', $css);
        $this->assertStringContainsString('--sidebar-background: #000000;', $css);
        $this->assertStringContainsString('height: 2.75rem;', $css);
        $this->assertStringContainsString('Appearance', $settings);
        $this->assertFileExists(resource_path('js/pages/settings/Appearance.vue'));
        $this->assertFileExists(resource_path('js/components/AppearanceTabs.vue'));
    }
}
