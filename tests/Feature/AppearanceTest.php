<?php

namespace Tests\Feature;

use App\Models\BrandProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AppearanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_dark_appearance_cookie_marks_the_html_element(): void
    {
        $response = $this
            ->withUnencryptedCookie('appearance', 'dark')
            ->get(route('home'));

        $response->assertOk();
        $html = (string) $response->getContent();
        $this->assertMatchesRegularExpression(
            '/<html[^>]*\bclass="[^"]*\bdark\b/',
            $html,
        );
        $this->assertStringContainsString("const appearance = 'dark';", $html);
        $this->assertStringContainsString('background-color: #0e0e10', $html);
        $this->assertStringContainsString("root.style.colorScheme = dark ? 'dark' : 'light';", $html);
        $this->assertStringContainsString('color-scheme: dark;', $html);
    }

    public function test_light_appearance_cookie_does_not_force_dark_class(): void
    {
        $response = $this
            ->withUnencryptedCookie('appearance', 'light')
            ->get(route('home'));

        $response->assertOk();
        $html = (string) $response->getContent();
        $this->assertDoesNotMatchRegularExpression(
            '/<html[^>]*\bclass="[^"]*\bdark\b/',
            $html,
        );
        $this->assertStringContainsString("const appearance = 'light';", $html);
        $this->assertStringContainsString('background-color: #edeae2', $html);
    }

    public function test_authenticated_users_can_open_appearance_settings(): void
    {
        $user = User::factory()->create();
        BrandProfile::factory()->for($user)->create();

        $this
            ->actingAs($user)
            ->get(route('appearance.edit'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('settings/Appearance'));
    }

    public function test_authenticated_users_follow_light_appearance_cookie(): void
    {
        $user = User::factory()->create();
        BrandProfile::factory()->for($user)->create();

        $response = $this
            ->actingAs($user)
            ->withUnencryptedCookie('appearance', 'light')
            ->get(route('dashboard'));

        $response->assertOk();
        $html = (string) $response->getContent();
        $this->assertStringNotContainsString('data-app-night="1"', $html);
        $this->assertDoesNotMatchRegularExpression(
            '/<html[^>]*\bclass="[^"]*\bdark\b/',
            $html,
        );
        $this->assertStringContainsString("const appearance = 'light';", $html);
    }

    public function test_app_chrome_fills_the_viewport_and_exposes_appearance(): void
    {
        $layout = file_get_contents(resource_path('js/layouts/app/AppSidebarLayout.vue'));
        $settings = file_get_contents(resource_path('js/layouts/settings/Layout.vue'));
        $header = file_get_contents(resource_path('js/components/AppSidebarHeader.vue'));
        $css = file_get_contents(resource_path('css/app.css'));
        $vite = file_get_contents(base_path('vite.config.ts'));

        $this->assertIsString($layout);
        $this->assertIsString($settings);
        $this->assertIsString($header);
        $this->assertNotFalse($css);
        $this->assertNotFalse($vite);
        $this->assertStringContainsString('min-h-[calc(100svh-4rem)]', $layout);
        $this->assertStringContainsString('snitch-app-chrome', $layout);
        $this->assertStringContainsString('bg-snitch-paper', $layout);
        $this->assertStringNotContainsString('snitch-app-night', $layout);
        $this->assertStringNotContainsString(' class="snitch-app-night dark', $layout);
        $this->assertStringContainsString('bg-snitch-paper', $header);
        $this->assertStringContainsString('border-snitch-ink/10', $header);
        $this->assertStringContainsString('bg-snitch-paper', $settings);
        $this->assertStringContainsString('Appearance', $settings);
        $this->assertStringContainsString('snitch-app-chrome', $css);
        $this->assertStringContainsString('font-size: 14px', $css);
        $this->assertStringContainsString("bunny('Inter'", $vite);
        $this->assertStringContainsString("bunny('Archivo Black'", $vite);
    }

    public function test_light_and_dark_caution_tape_tokens_are_defined(): void
    {
        $css = file_get_contents(resource_path('css/app.css'));

        $this->assertNotFalse($css);
        $this->assertStringContainsString('--snitch-paper: #edeae2;', $css);
        $this->assertStringContainsString('--snitch-ink: #0e0e10;', $css);
        $this->assertStringContainsString('--snitch-spot: #fcd700;', $css);
        $this->assertStringContainsString('--snitch-alert: #ff3d8b;', $css);
        $this->assertStringContainsString('--snitch-caution-yellow: #fcd700;', $css);
        $this->assertStringContainsString('--snitch-caution-pink: #ff3d8b;', $css);
        $this->assertStringContainsString('--snitch-paper: #0e0e10;', $css);
        $this->assertStringContainsString('--snitch-ink: #edeae2;', $css);
        $this->assertStringContainsString('--sidebar-accent: #e0ddd4;', $css);
        $this->assertStringContainsString('--sidebar-accent: #1e1e22;', $css);
        $this->assertStringContainsString('height: 2.75rem;', $css);
        $this->assertStringContainsString('.snitch-nav-current', $css);
        $this->assertStringContainsString('background-color: #fcd700 !important;', $css);
        $this->assertStringContainsString('.dark .snitch-choice-active', $css);
        $this->assertStringNotContainsString('--snitch-paper: #f3eee3;', $css);
        $this->assertStringNotContainsString('--snitch-spot: #ffd60a;', $css);
    }

    public function test_yellow_button_hover_uses_charcoal_on_spot_type(): void
    {
        $css = file_get_contents(resource_path('css/app.css'));

        $this->assertNotFalse($css);
        $this->assertMatchesRegularExpression(
            '/\.snitch-btn:hover\s*\{[^}]*color:\s*var\(--snitch-on-spot\)/s',
            $css,
        );
    }

    public function test_landing_uses_caution_tape_accents(): void
    {
        $welcome = file_get_contents(resource_path('js/pages/Welcome.vue'));

        $this->assertNotFalse($welcome);
        $this->assertStringContainsString('bg-snitch-caution-ink', $welcome);
        $this->assertStringContainsString('bg-snitch-caution-yellow', $welcome);
        $this->assertStringContainsString('snitch-caution-pink', $welcome);
        $this->assertStringContainsString('snitch-hero-display', $welcome);
    }
}
