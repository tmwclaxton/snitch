<?php

namespace Tests\Feature;

use Tests\TestCase;

class CautionTapeAppChromeTest extends TestCase
{
    public function test_logged_in_shell_is_pinned_to_caution_tape_night(): void
    {
        $layout = file_get_contents(resource_path('js/layouts/app/AppSidebarLayout.vue'));
        $appearance = file_get_contents(app_path('Http/Middleware/HandleAppearance.php'));
        $blade = file_get_contents(resource_path('views/app.blade.php'));
        $theme = file_get_contents(resource_path('js/composables/useAppearance.ts'));
        $charts = file_get_contents(resource_path('js/lib/snitchTheme.ts'));
        $sidebar = file_get_contents(resource_path('js/components/AppSidebar.vue'));
        $routes = file_get_contents(base_path('routes/settings.php'));

        $this->assertNotFalse($layout);
        $this->assertNotFalse($appearance);
        $this->assertNotFalse($blade);
        $this->assertNotFalse($theme);
        $this->assertNotFalse($charts);
        $this->assertNotFalse($sidebar);
        $this->assertNotFalse($routes);

        $this->assertStringContainsString('snitch-app-night', $layout);
        $this->assertStringContainsString("View::share('appNight', \$request->user() !== null)", $appearance);
        $this->assertStringContainsString('data-app-night=', $blade);
        $this->assertStringContainsString("const appNight = document.documentElement.dataset.appNight === '1'", $theme);
        $this->assertStringContainsString('dataset.appNight', $charts);
        $this->assertStringContainsString('snitch-app-night', $charts);
        $this->assertStringContainsString('bg-black', $sidebar);
        $this->assertStringContainsString('editProfile()', $sidebar);
        $this->assertStringContainsString("Route::redirect('settings/appearance', '/settings/profile')", $routes);
        $this->assertStringContainsString('Keep settings/Appearance.vue', $routes);
    }

    public function test_caution_tape_app_tokens_and_bands_are_wired(): void
    {
        $css = file_get_contents(resource_path('css/app.css'));
        $tile = file_get_contents(resource_path('js/components/dashboard/ExecStatTile.vue'));
        $takeaways = file_get_contents(resource_path('js/components/dashboard/ExecTakeaways.vue'));
        $brief = file_get_contents(resource_path('js/pages/brief/Index.vue'));
        $chart = file_get_contents(resource_path('js/components/growth/GrowthLineChart.vue'));
        $settings = file_get_contents(resource_path('js/layouts/settings/Layout.vue'));

        $this->assertNotFalse($css);
        $this->assertNotFalse($tile);
        $this->assertNotFalse($takeaways);
        $this->assertNotFalse($brief);
        $this->assertNotFalse($chart);
        $this->assertNotFalse($settings);

        $this->assertStringContainsString('html:has(.snitch-app-night)', $css);
        $this->assertStringContainsString('.snitch-caution-band', $css);
        $this->assertStringContainsString('.snitch-caution-card', $css);
        $this->assertStringContainsString('.snitch-gap-lower', $css);
        $this->assertStringContainsString('.snitch-stat-accent', $css);
        $this->assertStringContainsString('--sidebar-background: #000000;', $css);
        $this->assertStringContainsString('text-transform: uppercase;', $css);
        $this->assertDoesNotMatchRegularExpression(
            '/\.dark \.snitch-format-tag\s*\{[^}]*background:\s*var\(--snitch-spot\)/s',
            $css,
        );
        $this->assertStringNotContainsString('highlightValue', $tile);
        $this->assertStringContainsString('snitch-stat-accent', $tile);
        $this->assertStringContainsString('snitch-gap-lower', $tile);
        $this->assertStringContainsString('snitch-stat-accent', $takeaways);
        $this->assertStringContainsString('snitch-caution-band', $brief);
        $this->assertStringContainsString('snitch-caution-num', $brief);
        $this->assertStringContainsString('snitchApexTheme()', $chart);
        $this->assertStringContainsString('Appearance is hidden while the logged-in app is pinned', $settings);
        $this->assertFileExists(resource_path('js/pages/settings/Appearance.vue'));
        $this->assertFileExists(resource_path('js/components/AppearanceTabs.vue'));
    }
}
