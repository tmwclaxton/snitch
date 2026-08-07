<?php

namespace Tests\Feature\Marketing;

use App\Mail\Marketing\ContactMessage;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PublicPagesTest extends TestCase
{
    public function test_home_page_is_successful(): void
    {
        $this->get(route('home'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Welcome')
            );
    }

    public function test_platform_logo_assets_exist(): void
    {
        foreach (['tiktok', 'instagram', 'facebook', 'linkedin', 'pinterest'] as $slug) {
            $this->assertFileExists(public_path("images/platforms/{$slug}.svg"));
        }
    }

    public function test_hero_mascot_assets_exist(): void
    {
        $this->assertFileExists(public_path('images/marketing/hero/mascot-character.png'));
        $this->assertFileExists(public_path('images/marketing/hero/mascot-binos.png'));
    }

    public function test_brand_logo_uses_lovable_mascot_mark(): void
    {
        $this->assertFileExists(public_path('images/brand/mascot-mark.png'));
        $this->assertFileExists(public_path('favicon.png'));
        $this->assertFileExists(public_path('favicon.ico'));
        $this->assertFileExists(public_path('favicon.svg'));
        $this->assertFileExists(public_path('apple-touch-icon.png'));

        $chrome = [
            resource_path('js/components/SnitchBrand.vue'),
            resource_path('js/components/AppLogoIcon.vue'),
        ];

        foreach ($chrome as $path) {
            $contents = file_get_contents($path);
            $this->assertNotFalse($contents, "Missing source file: {$path}");
            $this->assertStringContainsString('/images/brand/mascot-mark.png', $contents);
            $this->assertStringNotContainsString('/images/brand/snitch-mark.png', $contents);
            $this->assertStringNotContainsString('/images/brand/detective-mark.png', $contents);
        }
    }

    public function test_marketing_pages_are_successful_for_guests(): void
    {
        $pages = [
            'about' => 'marketing/About',
            'how-it-works' => 'marketing/HowItWorks',
            'contact' => 'marketing/Contact',
            'privacy' => 'marketing/Privacy',
            'terms' => 'marketing/Terms',
            'cookies' => 'marketing/Cookies',
        ];

        foreach ($pages as $route => $component) {
            $this->get(route($route))
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->component($component)
                );
        }
    }

    public function test_marketing_sources_omit_draft_legal_disclaimers(): void
    {
        $paths = [
            resource_path('js/components/marketing/LegalDocument.vue'),
            resource_path('js/components/marketing/PublicFooter.vue'),
            resource_path('js/pages/marketing/Privacy.vue'),
            resource_path('js/pages/marketing/Terms.vue'),
            resource_path('js/pages/marketing/Cookies.vue'),
            resource_path('js/pages/Welcome.vue'),
        ];

        $forbidden = [
            'Draft legal copy',
            'Have a lawyer review',
            'Have counsel review',
            'not legal advice',
            'Draft for product v1',
            'This draft policy',
            'These draft terms',
            'This draft notice',
        ];

        foreach ($paths as $path) {
            $contents = file_get_contents($path);
            $this->assertNotFalse($contents, "Missing source file: {$path}");

            foreach ($forbidden as $needle) {
                $this->assertStringNotContainsString(
                    $needle,
                    $contents,
                    "Draft disclaimer \"{$needle}\" must not appear in {$path}",
                );
            }
        }
    }

    public function test_footer_routes_resolve_for_guests(): void
    {
        foreach (['about', 'how-it-works', 'contact', 'privacy', 'terms', 'cookies'] as $route) {
            $this->get(route($route))->assertOk();
        }
    }

    public function test_sitemap_lists_public_routes(): void
    {
        $response = $this->get(route('sitemap'));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/xml');
        $response->assertSee(route('home', absolute: true), false);
        $response->assertSee(route('privacy', absolute: true), false);
        $response->assertSee(route('terms', absolute: true), false);
        $response->assertSee(route('cookies', absolute: true), false);
        $response->assertSee(route('contact', absolute: true), false);
        $response->assertSee(route('about', absolute: true), false);
    }

    public function test_contact_form_sends_mail(): void
    {
        Mail::fake();

        $this->post(route('contact.store'), [
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.com',
            'message' => 'Hello from the contact form.',
        ])->assertRedirect();

        Mail::assertSent(ContactMessage::class);
    }

    public function test_contact_page_uses_readable_ink_contrast(): void
    {
        $contents = file_get_contents(resource_path('js/pages/marketing/Contact.vue'));

        $this->assertNotFalse($contents, 'Missing Contact.vue source');
        $this->assertStringContainsString('snitch-stamp-active', $contents);
        $this->assertStringContainsString('contact-annotation', $contents);
        $this->assertStringContainsString('text-snitch-ink', $contents);
        $this->assertDoesNotMatchRegularExpression(
            '/class="snitch-annotation(?![^"]*text-snitch-ink)/',
            $contents,
            'Contact annotations must use charcoal ink, not yellow-on-paper alone',
        );
    }

    public function test_hero_title_card_ctas_use_spot_and_ink_buttons(): void
    {
        $welcome = file_get_contents(resource_path('js/pages/Welcome.vue'));
        $css = file_get_contents(resource_path('css/app.css'));

        $this->assertNotFalse($welcome, 'Missing Welcome.vue source');
        $this->assertNotFalse($css, 'Missing app.css source');

        $this->assertMatchesRegularExpression(
            '/class="snitch-hero-cta[^"]*"[\s\S]*?class="snitch-btn">[\s\S]*?Log in[\s\S]*?class="snitch-btn snitch-btn-spot"[\s\S]*?Sign up/',
            $welcome,
            'Hero title card must use charcoal snitch-btn for Log in then spot yellow for Sign up',
        );
        $this->assertMatchesRegularExpression(
            '/class="snitch-hero-cta[^"]*"[\s\S]*?:href="login\(\)"[\s\S]*?:href="login\(\)"/',
            $welcome,
            'Hero title card Log in and Sign up must both use login() like PublicNav',
        );
        $this->assertDoesNotMatchRegularExpression(
            '/class="snitch-hero-cta[^"]*"[\s\S]*?snitch-btn-ghost/',
            $welcome,
            'Hero title card CTAs must not use ghost / paper fill',
        );
        $this->assertStringContainsString('.snitch-hero-copy .snitch-btn-spot', $css);
        $this->assertStringNotContainsString('.snitch-hero-copy .snitch-btn-ghost', $css);
    }

    public function test_unknown_public_path_renders_branded_not_found(): void
    {
        $this->get('/this-page-does-not-exist-snitch')
            ->assertNotFound()
            ->assertInertia(fn (Assert $page) => $page
                ->component('errors/NotFound')
            );
    }
}
