<?php

namespace Tests\Feature\Marketing;

use App\Mail\Marketing\ContactMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PublicPagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_home_page_is_successful(): void
    {
        $this->get(route('home'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Welcome')
            );
    }

    public function test_surface_clips_grain_so_it_does_not_expand_scroll(): void
    {
        $css = file_get_contents(resource_path('css/app.css'));
        $layout = file_get_contents(resource_path('js/layouts/PublicLayout.vue'));

        $this->assertNotFalse($css, 'Missing app.css source');
        $this->assertNotFalse($layout, 'Missing PublicLayout.vue source');
        $this->assertMatchesRegularExpression(
            '/\.snitch-surface\s*\{[^}]*overflow:\s*clip/s',
            $css,
            'snitch-surface must clip both axes to avoid a nested mobile scrollport',
        );
        $this->assertMatchesRegularExpression(
            '/@keyframes snitch-grain-drift\s*\{[^}]*background-position:/s',
            $css,
            'Grain drift must use background-position, not transform (transform expands scrollHeight)',
        );
        $this->assertStringNotContainsString(
            'overflow-x-clip',
            $layout,
            'PublicLayout should rely on .snitch-surface overflow:clip, not overflow-x-clip alone',
        );
    }

    public function test_platform_logo_assets_exist(): void
    {
        foreach (['tiktok', 'instagram', 'facebook', 'linkedin'] as $slug) {
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
            'pricing' => 'marketing/Pricing',
            'agents' => 'marketing/Agents',
            'analytics' => 'marketing/Analytics',
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
        foreach (['about', 'how-it-works', 'pricing', 'agents', 'analytics', 'contact', 'privacy', 'terms', 'cookies', 'blog.index'] as $route) {
            $this->get(route($route))->assertOk();
        }
    }

    public function test_marketing_copy_matches_current_product(): void
    {
        $pricing = file_get_contents(resource_path('js/pages/marketing/Pricing.vue'));
        $privacy = file_get_contents(resource_path('js/pages/marketing/Privacy.vue'));
        $terms = file_get_contents(resource_path('js/pages/marketing/Terms.vue'));
        $how = file_get_contents(resource_path('js/pages/marketing/HowItWorks.vue'));
        $about = file_get_contents(resource_path('js/pages/marketing/About.vue'));

        $this->assertNotFalse($pricing);
        $this->assertNotFalse($privacy);
        $this->assertNotFalse($terms);
        $this->assertNotFalse($how);
        $this->assertNotFalse($about);

        $this->assertStringNotContainsString('seats', strtolower($pricing));
        $this->assertStringContainsString('usage credits every billing period', $pricing);
        $this->assertStringContainsString('Feed, Explore, Winners', $pricing);
        $this->assertStringContainsString('Live tool averages', $pricing);
        $this->assertStringContainsString('formatPenceAsGbp', $pricing);
        $this->assertStringContainsString('Stripe', $privacy);
        $this->assertStringContainsString('Stripe', $terms);
        $this->assertStringContainsString('YouTube Shorts', $how);
        $this->assertStringContainsString('Explore', $how);
        $this->assertStringContainsString('Blog', $about);
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
        $response->assertSee(route('analytics', absolute: true), false);
        $response->assertSee(route('pricing', absolute: true), false);
    }

    public function test_contact_form_sends_mail(): void
    {
        Mail::fake();
        config(['snitch.contact_to' => 'tmwclaxton@gmail.com']);

        $this->post(route('contact.store'), [
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.com',
            'message' => 'Hello from the contact form.',
        ])->assertRedirect();

        Mail::assertSent(ContactMessage::class, function (ContactMessage $mail): bool {
            return $mail->hasTo('tmwclaxton@gmail.com')
                && $mail->email === 'ada@example.com'
                && $mail->name === 'Ada Lovelace';
        });
    }

    public function test_contact_page_shows_snitchsocial_support_address(): void
    {
        $contents = file_get_contents(resource_path('js/pages/marketing/Contact.vue'));

        $this->assertNotFalse($contents);
        $this->assertStringContainsString('hello@snitchsocial.net', $contents);
        $this->assertStringNotContainsString('hello@snitch.app', $contents);
    }

    public function test_browser_tab_title_defaults_to_snitch_not_laravel(): void
    {
        $appTs = file_get_contents(resource_path('js/app.ts'));
        $envExample = file_get_contents(base_path('.env.example'));
        $blade = file_get_contents(resource_path('views/app.blade.php'));

        $this->assertNotFalse($appTs);
        $this->assertNotFalse($envExample);
        $this->assertNotFalse($blade);

        $this->assertStringContainsString("|| 'Snitch'", $appTs);
        $this->assertStringNotContainsString("|| 'Laravel'", $appTs);
        $this->assertStringContainsString('createSSRApp', $appTs);
        $this->assertStringContainsString('return vueApp', $appTs);
        $this->assertMatchesRegularExpression('/^APP_NAME=Snitch$/m', $envExample);
        $this->assertStringContainsString("config('app.name', 'Snitch')", $blade);
        $this->assertSame('Snitch', config('app.name'));
    }

    public function test_contact_page_uses_readable_ink_contrast(): void
    {
        $contents = file_get_contents(resource_path('js/pages/marketing/Contact.vue'));

        $this->assertNotFalse($contents, 'Missing Contact.vue source');
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
            '/class="snitch-hero-cta[^"]*"[\s\S]*?class="snitch-btn">[\s\S]*?relative z-10[^"]*">[\s\S]*?Log in[\s\S]*?class="snitch-btn snitch-btn-spot"[\s\S]*?relative z-10[^"]*">[\s\S]*?Sign up/',
            $welcome,
            'Hero title card must use charcoal snitch-btn for Log in then spot yellow for Sign up, with visible z-10 labels',
        );
        $this->assertMatchesRegularExpression(
            '/class="snitch-hero-cta[^"]*"[\s\S]*?v-if="isAuthenticated"[\s\S]*?class="snitch-btn snitch-btn-spot"[\s\S]*?relative z-10[^"]*">[\s\S]*?Dashboard/',
            $welcome,
            'Authenticated hero CTA must be yellow spot ticket Dashboard with visible label',
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
        $this->assertStringContainsString('.snitch-hero-copy .snitch-btn.snitch-btn-spot', $css);
        $this->assertStringContainsString('.snitch-hero-copy .snitch-btn.snitch-btn-spot::before', $css);
        $this->assertStringNotContainsString('.snitch-hero-copy .snitch-btn-ghost', $css);
        $this->assertDoesNotMatchRegularExpression(
            '/\.snitch-hero-copy\s+\.snitch-btn\s*\{[^}]*container-type\s*:/s',
            $css,
            'Hero buttons must not use container-type (inline-size containment collapses label width)',
        );
        $this->assertMatchesRegularExpression(
            '/\.snitch-hero-copy\s+\.snitch-btn\.snitch-btn-spot::before\s*\{[^}]*inset:\s*var\(--snitch-ticket-stroke\)/s',
            $css,
            'Hero spot face must use the same inset charcoal rim as closing Open dashboard',
        );
    }

    public function test_hero_background_is_static_on_mobile(): void
    {
        $css = file_get_contents(resource_path('css/app.css'));

        $this->assertNotFalse($css, 'Missing app.css source');
        $this->assertMatchesRegularExpression(
            '/@media\s*\(max-width:\s*767px\)\s*\{[^}]*\.snitch-hero-bg-img\s*\{[^}]*height:\s*100%/s',
            $css,
            'Mobile hero wall image must fill the hero box instead of the tall desktop crop',
        );
        $this->assertMatchesRegularExpression(
            '/@media\s*\(max-width:\s*767px\)\s*\{[\s\S]*?\.snitch-hero-marquee-track\s*\{[^}]*animation:\s*none/s',
            $css,
            'Mobile hero must freeze parallax marquee tracks',
        );
    }

    public function test_home_has_mobile_poster_hero_separate_from_desktop(): void
    {
        $welcome = file_get_contents(resource_path('js/pages/Welcome.vue'));
        $css = file_get_contents(resource_path('css/app.css'));

        $this->assertNotFalse($welcome, 'Missing Welcome.vue source');
        $this->assertNotFalse($css, 'Missing app.css source');

        $this->assertStringContainsString('snitch-hero-mobile', $welcome);
        $this->assertStringContainsString('md:hidden', $welcome);
        $this->assertStringContainsString('mobileHeroEl', $welcome);
        $this->assertStringContainsString('measureVisibleViewportHeight', $welcome);
        $this->assertStringContainsString('--snitch-mobile-hero-height', $welcome);
        $this->assertStringContainsString('lockedMobileHeroHeight', $welcome);
        $this->assertStringContainsString('orientationchange', $welcome);
        $this->assertStringNotContainsString(
            "visualViewport?.addEventListener('resize'",
            $welcome,
            'Do not remeasure on visualViewport resize - URL bar show/hide would resize the hero while scrolling',
        );
        $this->assertStringContainsString(
            'class="snitch-hero relative hidden h-dvh w-full overflow-hidden md:block"',
            $welcome,
            'Desktop wall hero must stay hidden below md',
        );
        $this->assertStringContainsString('snitch-hero-mobile-mascot', $welcome);
        $this->assertStringContainsString('snitch-hero-mobile-floor', $welcome);
        $this->assertStringContainsString('snitch-hero-mobile-cta', $welcome);
        $this->assertDoesNotMatchRegularExpression(
            '/snitch-hero-mobile[\s\S]{0,400}pt-44/',
            $welcome,
            'Mobile poster must not use the oversized pt-44 gap under the nav',
        );
        $this->assertMatchesRegularExpression(
            '/snitch-hero-mobile-cta[\s\S]*?Get started[\s\S]*?Log in/',
            $welcome,
            'Mobile poster must lead with Get started then Log in',
        );
        $this->assertStringContainsString('.snitch-hero-mobile-wash', $css);
        $this->assertStringContainsString('.snitch-hero-mobile-floor', $css);
        $this->assertStringContainsString('snitch-hero-mobile-mascot-bob', $css);
        $this->assertStringContainsString(
            'height: var(--snitch-mobile-hero-height, 100dvh)',
            $css,
        );
        $this->assertStringContainsString(
            'max-height: var(--snitch-mobile-hero-height, 100dvh)',
            $css,
        );
    }

    public function test_hero_backdrop_waits_for_decode_before_reveal(): void
    {
        $welcome = file_get_contents(resource_path('js/pages/Welcome.vue'));
        $css = file_get_contents(resource_path('css/app.css'));

        $this->assertNotFalse($welcome, 'Missing Welcome.vue source');
        $this->assertNotFalse($css, 'Missing app.css source');
        $this->assertStringContainsString('heroBackdropReady', $welcome);
        $this->assertStringContainsString('preloadHeroImage', $welcome);
        $this->assertStringContainsString("matchMedia('(min-width: 768px)')", $welcome);
        $this->assertStringContainsString('snitch-hero-backdrop-placeholder', $welcome);
        $this->assertStringContainsString('class="snitch-hero-backdrop"', $welcome);
        $this->assertStringContainsString('v-if="desktopHeroArt"', $welcome);
        $this->assertStringContainsString("'is-ready': heroBackdropReady", $welcome);
        $this->assertStringContainsString('.snitch-hero-backdrop.is-ready', $css);
        $this->assertMatchesRegularExpression(
            '/\.snitch-hero-backdrop\s*\{[^}]*filter:\s*blur\(/s',
            $css,
            'Hero backdrop must stay blurred until layers are ready',
        );
    }

    public function test_ghost_ticket_buttons_stroke_follows_clip_not_inset_shadow(): void
    {
        $css = file_get_contents(resource_path('css/app.css'));
        $nav = file_get_contents(resource_path('js/components/marketing/PublicNav.vue'));

        $this->assertNotFalse($css, 'Missing app.css source');
        $this->assertNotFalse($nav, 'Missing PublicNav.vue source');

        $this->assertStringContainsString(
            'snitch-btn snitch-btn-ghost',
            $nav,
            'PublicNav Dashboard / Log out must use ghost ticket buttons',
        );
        $this->assertStringContainsString('--snitch-ticket-stroke', $css);
        $this->assertStringContainsString('.snitch-btn-ghost::before', $css);
        $this->assertStringNotContainsString(
            'box-shadow: inset 0 0 0 2px var(--snitch-ink)',
            $css,
            'Ghost ticket outline must not use rectangular inset box-shadow under clip-path',
        );
    }

    public function test_spot_ticket_buttons_use_charcoal_outline_via_inset_face(): void
    {
        $css = file_get_contents(resource_path('css/app.css'));
        $welcome = file_get_contents(resource_path('js/pages/Welcome.vue'));

        $this->assertNotFalse($css, 'Missing app.css source');
        $this->assertNotFalse($welcome, 'Missing Welcome.vue source');

        $this->assertStringContainsString(
            'Start tracking the competition.',
            $welcome,
        );
        $this->assertMatchesRegularExpression(
            '/Start tracking the competition\.[\s\S]*?class="snitch-btn snitch-btn-spot"/',
            $welcome,
            'Closing CTA must use spot yellow with global charcoal ticket outline',
        );
        $this->assertStringContainsString('.snitch-btn.snitch-btn-spot::before', $css);
        $this->assertStringContainsString(
            'inset: var(--snitch-ticket-stroke)',
            $css,
            'Spot yellow face must inset by ticket stroke so charcoal rim follows clip',
        );
        $this->assertMatchesRegularExpression(
            '/\.snitch-btn\.snitch-btn-spot\s*\{[^}]*background:\s*var\(--snitch-press\)/s',
            $css,
            'Spot outer fill must be charcoal so the wavy rim reads as outline',
        );
        $this->assertMatchesRegularExpression(
            '/\.snitch-btn\.snitch-btn-spot::before\s*\{[^}]*background:\s*var\(--snitch-spot\)/s',
            $css,
            'Spot inset face must stay yellow',
        );
        $this->assertStringNotContainsString(
            'box-shadow: inset 0 0 0 2px var(--snitch-ink)',
            $css,
            'Spot ticket outline must not use rectangular inset box-shadow under clip-path',
        );
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
