<?php

namespace Tests\Feature;

use App\Models\BrandProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
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
    }

    public function test_authenticated_users_can_visit_appearance_settings(): void
    {
        $user = User::factory()->create();
        BrandProfile::factory()->for($user)->create();

        $this
            ->actingAs($user)
            ->get(route('appearance.edit'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('settings/Appearance')
            );
    }

    public function test_app_chrome_fills_the_viewport_on_evidence_file_shell(): void
    {
        $layout = file_get_contents(resource_path('js/layouts/app/AppSidebarLayout.vue'));
        $settings = file_get_contents(resource_path('js/layouts/settings/Layout.vue'));
        $css = file_get_contents(resource_path('css/app.css'));
        $vite = file_get_contents(base_path('vite.config.ts'));

        $this->assertIsString($layout);
        $this->assertIsString($settings);
        $this->assertNotFalse($css);
        $this->assertNotFalse($vite);
        $this->assertStringContainsString('min-h-[calc(100svh-4rem)]', $layout);
        $this->assertStringContainsString('snitch-app-chrome', $layout);
        $this->assertStringContainsString('bg-snitch-paper', $layout);
        $this->assertStringNotContainsString('min-h-[50vh]', $layout);
        $this->assertStringContainsString('bg-snitch-paper', $settings);
        $this->assertStringContainsString('snitch-highlight', $settings);
        $this->assertStringContainsString('snitch-app-chrome', $css);
        $this->assertStringContainsString('font-size: 14px', $css);
        $this->assertStringContainsString('font-size: 15px', $css);
        $this->assertStringContainsString('.text-\\[10px\\]', $css);
        $this->assertStringContainsString('.text-\\[11px\\], .text-\\[12px\\]', $css);
        $this->assertStringContainsString('snitch-dash-chip-stack .snitch-glance-tag', $css);
        $this->assertStringContainsString('font-size: 0.875rem; /* 14px body floor */', $css);
        $this->assertStringContainsString('Authenticated type floors (unlayered', $css);
        $this->assertMatchesRegularExpression(
            '/\.snitch-app-shell\s*\{[^}]*min-height:\s*calc\(100svh\s*-\s*4rem\)/s',
            $css,
        );
        $this->assertStringContainsString("bunny('Inter'", $vite);
        $this->assertStringContainsString("bunny('Bricolage Grotesque'", $vite);
        $this->assertStringContainsString("bunny('Space Mono'", $vite);
        $this->assertStringContainsString("bunny('Archivo Black'", $vite);
    }

    public function test_evidence_file_tokens_are_defined_for_light_and_dark(): void
    {
        $css = file_get_contents(resource_path('css/app.css'));

        $this->assertNotFalse($css);
        $this->assertStringContainsString('--snitch-paper: #f3eee3;', $css);
        $this->assertStringContainsString('--snitch-ink: #141414;', $css);
        $this->assertStringContainsString('--snitch-spot: #ffd60a;', $css);
        $this->assertStringContainsString('--snitch-alert: #e5341d;', $css);
        $this->assertStringContainsString('--snitch-caution-ink: #0e0e10;', $css);
        $this->assertStringContainsString('--snitch-caution-yellow: #fcd700;', $css);
        $this->assertStringContainsString('--snitch-caution-pink: #ff3d8b;', $css);
        $this->assertStringContainsString("'Bricolage Grotesque'", $css);
        $this->assertStringContainsString("'Space Mono'", $css);
        $this->assertStringContainsString("'Archivo Black'", $css);
        $this->assertStringContainsString("'Inter'", $css);
        $this->assertStringContainsString('--snitch-paper: #0e0e10;', $css);
        $this->assertStringContainsString('--snitch-ink: #edeae2;', $css);
        $this->assertStringContainsString('--snitch-press: #0e0e10;', $css);
        $this->assertStringContainsString('--snitch-on-spot: #0e0e10;', $css);
        $this->assertStringContainsString('--snitch-lift: #141416;', $css);
        $this->assertStringContainsString('--snitch-spot: #fcd700;', $css);
        $this->assertStringContainsString('--snitch-print-blend: soft-light;', $css);
        $this->assertStringContainsString('var(--snitch-lift)', $css);
        $this->assertStringContainsString('var(--snitch-print-blend)', $css);
        $this->assertStringContainsString('.snitch-highlight', $css);
        $this->assertStringContainsString('.snitch-alert-badge', $css);
        $this->assertStringContainsString('.snitch-hero-display', $css);
        $this->assertStringContainsString('.snitch-choice-active', $css);
        $this->assertStringContainsString('.snitch-format-tag', $css);
        $this->assertMatchesRegularExpression(
            '/\.snitch-format-tag\s*\{[^}]*width:\s*fit-content/s',
            $css,
        );
        $this->assertStringContainsString('.snitch-meter-fill-lead', $css);
        $this->assertMatchesRegularExpression(
            '/\.snitch-meter-fill-lead\s*\{[^}]*background:\s*var\(--snitch-ink\)/s',
            $css,
        );
        $this->assertMatchesRegularExpression(
            '/\.dark \.snitch-meter-fill-lead\s*\{[^}]*background:\s*var\(--snitch-spot\)/s',
            $css,
        );
        $this->assertStringContainsString('--snitch-heat: #141414;', $css);
        $this->assertStringContainsString("html:not(.dark) .snitch-app-chrome [data-slot='sidebar-menu-button'][data-active='true']", $css);
        $this->assertStringContainsString('.dark .snitch-choice-active', $css);
        $this->assertStringContainsString('background: var(--snitch-spot);', $css);
        $this->assertStringContainsString('html.dark {', $css);
        $this->assertStringContainsString('color-scheme: dark;', $css);
        $this->assertStringContainsString('scrollbar-color:', $css);
        $this->assertStringContainsString('::-webkit-scrollbar-thumb', $css);
    }

    public function test_yellow_button_hover_uses_charcoal_on_spot_type(): void
    {
        $css = file_get_contents(resource_path('css/app.css'));

        $this->assertNotFalse($css);
        $this->assertMatchesRegularExpression(
            '/\.snitch-btn:hover\s*\{[^}]*color:\s*var\(--snitch-on-spot\)/s',
            $css,
        );
        $this->assertMatchesRegularExpression(
            '/\.snitch-btn-ghost:hover,\s*\n\s*\.snitch-btn-ghost-light:hover\s*\{[^}]*color:\s*var\(--snitch-on-spot\)/s',
            $css,
        );
        $this->assertMatchesRegularExpression(
            '/\.snitch-platform-embed-open:hover\s*\{[^}]*background:\s*var\(--snitch-spot\);[^}]*color:\s*var\(--snitch-on-spot\)/s',
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
