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

    public function test_beta_landing_page_is_standalone(): void
    {
        $this->get(route('beta'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('marketing/Beta')
            );

        $contents = file_get_contents(resource_path('js/pages/marketing/Beta.vue'));

        $this->assertNotFalse($contents, 'Missing Beta.vue source');
        $this->assertStringContainsString('https://calendly.com/dan-olympuslab/30min', $contents);
        $this->assertStringContainsString('Book a free intro call', $contents);
        $this->assertStringContainsString('snitch-hero-mascot-peek', $contents,
            'Beta hero must reuse the main landing page mascot peek animation');
        $this->assertStringContainsString('snitch-hero-copy', $contents);
        $this->assertStringContainsString('snitch-hero-marquee-track', $contents,
            'Beta hero must reuse the main landing page sliding platform wall');
        $this->assertStringContainsString('/images/marketing/hero/platforms-front.png', $contents);
        $this->assertStringContainsString('heroBackdropReady', $contents,
            'Beta hero wall must wait for decode before reveal');
        $this->assertStringNotContainsString('PublicLayout', $contents);
        $this->assertStringNotContainsString('PublicNav', $contents);
        $this->assertStringNotContainsString('PublicFooter', $contents);
        $this->assertStringNotContainsString('@/routes', $contents,
            'Beta landing must not import route helpers - no links into the rest of the site');
    }

    public function test_beta_landing_page_stays_out_of_sitemap(): void
    {
        $response = $this->get(route('sitemap'));

        $response->assertOk();
        $response->assertDontSee(route('beta', absolute: true), false);
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
        $this->assertStringContainsString('Full competitor tracking', $pricing);
        $this->assertStringContainsString('Weekly refreshed data', $pricing);
        $this->assertStringNotContainsString('MCP + web app access', $pricing);
        $this->assertStringNotContainsString('Agents / MCP', $pricing);
        $this->assertStringContainsString('Live tool averages', $pricing);
        $this->assertStringContainsString('Mean charge per step', $pricing);
        $this->assertStringContainsString('avg / step', $pricing);
        $this->assertStringNotContainsString('per run', strtolower($pricing));
        $this->assertStringNotContainsString('avg / run', $pricing);
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

    public function test_landing_uses_start_tracking_cta_and_full_access_price(): void
    {
        $welcome = file_get_contents(resource_path('js/pages/Welcome.vue'));

        $this->assertNotFalse($welcome, 'Missing Welcome.vue source');
        $this->assertStringContainsString("Start tracking - it's free", $welcome);
        $this->assertStringContainsString("from '@/routes'", $welcome);
        $this->assertStringContainsString('login()', $welcome);
        $this->assertStringNotContainsString('calendly.com/dan-olympuslab', $welcome);
        $this->assertStringContainsString('£50', $welcome);
        $this->assertStringContainsString('Full Access', $welcome);
        $this->assertStringContainsString('Better social media performance.', $welcome);
    }

    public function test_beta_hero_background_is_static_on_mobile(): void
    {
        $css = file_get_contents(resource_path('css/app.css'));

        $this->assertNotFalse($css, 'Missing app.css source');
        $this->assertMatchesRegularExpression(
            '/@media\s*\(max-width:\s*767px\)\s*\{[^}]*\.snitch-hero-bg-img\s*\{[^}]*height:\s*100%/s',
            $css,
            'Beta hero wall image must fill the hero box instead of the tall desktop crop',
        );
        $this->assertMatchesRegularExpression(
            '/@media\s*\(max-width:\s*767px\)\s*\{[\s\S]*?\.snitch-hero-marquee-track\s*\{[^}]*animation:\s*none/s',
            $css,
            'Beta hero must freeze parallax marquee tracks on mobile',
        );
    }

    public function test_home_uses_lovable_core_copy(): void
    {
        $welcome = file_get_contents(resource_path('js/pages/Welcome.vue'));

        $this->assertNotFalse($welcome, 'Missing Welcome.vue source');
        $this->assertStringNotContainsString('desktopHeroArt', $welcome);
        $this->assertStringNotContainsString('platforms-front.png', $welcome);
        $this->assertStringContainsString('Stop scrolling your competitors', $welcome);
        $this->assertStringContainsString('Questions, answered', $welcome);
    }

    public function test_beta_hero_backdrop_waits_for_decode_before_reveal(): void
    {
        $beta = file_get_contents(resource_path('js/pages/marketing/Beta.vue'));
        $css = file_get_contents(resource_path('css/app.css'));

        $this->assertNotFalse($beta, 'Missing Beta.vue source');
        $this->assertNotFalse($css, 'Missing app.css source');
        $this->assertStringContainsString('heroBackdropReady', $beta);
        $this->assertStringContainsString('preloadHeroImage', $beta);
        $this->assertStringContainsString("matchMedia('(min-width: 768px)')", $beta);
        $this->assertStringContainsString('snitch-hero-backdrop-placeholder', $beta);
        $this->assertStringContainsString('class="snitch-hero-backdrop"', $beta);
        $this->assertStringContainsString('v-if="desktopHeroArt"', $beta);
        $this->assertStringContainsString("'is-ready': heroBackdropReady", $beta);
        $this->assertStringContainsString('.snitch-hero-backdrop.is-ready', $css);
        $this->assertMatchesRegularExpression(
            '/\.snitch-hero-backdrop\s*\{[^}]*filter:\s*blur\(/s',
            $css,
            'Hero backdrop must stay blurred until layers are ready',
        );
    }

    public function test_marketer_chrome_hides_mcp_and_influencer_find(): void
    {
        $nav = file_get_contents(resource_path('js/components/marketing/PublicNav.vue'));
        $footer = file_get_contents(resource_path('js/components/marketing/PublicFooter.vue'));
        $welcome = file_get_contents(resource_path('js/pages/Welcome.vue'));
        $sidebar = file_get_contents(resource_path('js/components/AppSidebar.vue'));

        $this->assertNotFalse($nav);
        $this->assertNotFalse($footer);
        $this->assertNotFalse($welcome);
        $this->assertNotFalse($sidebar);

        foreach ([$nav, $footer] as $chrome) {
            $this->assertStringNotContainsString("label: 'MCP'", $chrome);
        }

        $this->assertStringNotContainsString('Connect your agent', $welcome);
        $this->assertStringNotContainsString('find influencers', $welcome);
        $this->assertStringNotContainsString('Agents setup', $welcome);
        $this->assertStringContainsString('Better social media performance.', $welcome);
        $this->assertStringNotContainsString("title: 'MCP'", $sidebar);
        $this->assertStringNotContainsString("title: 'Brand Deals'", $sidebar);
        $this->assertStringContainsString("title: 'Competitors'", $sidebar);
    }

    public function test_public_nav_stays_minimal(): void
    {
        $nav = file_get_contents(resource_path('js/components/marketing/PublicNav.vue'));

        $this->assertNotFalse($nav, 'Missing PublicNav.vue source');
        $this->assertStringContainsString('aria-label="Snitch home"', $nav);
        $this->assertStringContainsString('Log in', $nav);
        $this->assertStringNotContainsString('How it works', $nav);
    }

    public function test_landing_page_does_not_push_open_source_section(): void
    {
        $welcome = file_get_contents(resource_path('js/pages/Welcome.vue'));

        $this->assertNotFalse($welcome, 'Missing Welcome.vue source');
        $this->assertStringNotContainsString('Fully open source', $welcome);
        $this->assertStringNotContainsString('Open source', $welcome);
        $this->assertStringNotContainsString('View source', $welcome);
        $this->assertStringNotContainsString(
            'GNU Affero General Public License v3.0 (AGPL-3.0)',
            $welcome,
        );
    }

    public function test_ghost_ticket_buttons_stroke_follows_clip_not_inset_shadow(): void
    {
        $css = file_get_contents(resource_path('css/app.css'));

        $this->assertNotFalse($css, 'Missing app.css source');
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

        $this->assertNotFalse($css, 'Missing app.css source');
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
