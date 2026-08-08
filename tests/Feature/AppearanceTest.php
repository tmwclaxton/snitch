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
        $this->assertStringContainsString('background-color: #1c1915', $html);
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

    public function test_dark_mode_defines_warm_snitch_paper_tokens(): void
    {
        $css = file_get_contents(resource_path('css/app.css'));

        $this->assertNotFalse($css);
        $this->assertStringContainsString('--snitch-paper: #1c1915;', $css);
        $this->assertStringContainsString('--snitch-ink: #efe6d8;', $css);
        $this->assertStringContainsString('--snitch-press: #1c1b1a;', $css);
        $this->assertStringContainsString('--snitch-on-spot: #1c1b1a;', $css);
        $this->assertStringContainsString('--snitch-lift: #3a342c;', $css);
        $this->assertStringContainsString('--snitch-print-blend: soft-light;', $css);
        $this->assertStringContainsString('var(--snitch-lift)', $css);
        $this->assertStringContainsString('var(--snitch-print-blend)', $css);
    }
}
