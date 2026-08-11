<?php

namespace Tests\Feature;

use App\Models\BrandProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class SeoTest extends TestCase
{
    use RefreshDatabase;

    public function test_home_html_includes_first_byte_seo_meta(): void
    {
        $home = config('seo.pages.home');
        $appUrl = rtrim((string) config('app.url'), '/');

        $response = $this->get(route('home'));

        $response->assertOk();
        $response->assertSee('name="description"', false);
        $response->assertSee(e($home['description']), false);
        $response->assertSee('property="og:title"', false);
        $response->assertSee('Snitch social tracking - Snitch', false);
        $response->assertSee('rel="canonical"', false);
        $response->assertSee($appUrl.'/', false);
        $response->assertSee('application/ld+json', false);
        $response->assertSee('"@type":"WebSite"', false);
        $response->assertSee('name="robots"', false);
        $response->assertSee('content="index, follow"', false);
        $response->assertSee('property="og:image"', false);
        $response->assertSee($appUrl.'/images/marketing/og.jpg', false);
    }

    public function test_og_preview_image_uses_cartoon_mascot_asset(): void
    {
        $path = public_path('images/marketing/og.jpg');

        $this->assertFileExists($path);
        $this->assertSame('/images/marketing/og.jpg', config('seo.default_image'));

        $size = getimagesize($path);
        $this->assertIsArray($size);
        $this->assertSame(1200, $size[0]);
        $this->assertSame(630, $size[1]);
    }

    public function test_public_pages_expose_unique_descriptions_from_config(): void
    {
        $routes = [
            'about',
            'how-it-works',
            'pricing',
            'analytics',
            'contact',
            'privacy',
            'terms',
            'cookies',
        ];

        foreach ($routes as $route) {
            $description = config("seo.pages.{$route}.description");
            $this->assertIsString($description);
            $this->assertNotSame('', $description);

            $this->get(route($route))
                ->assertOk()
                ->assertSee(e($description), false);
        }
    }

    public function test_not_found_is_noindex(): void
    {
        $this->get('/this-page-does-not-exist-snitch-seo')
            ->assertNotFound()
            ->assertSee('content="noindex, nofollow"', false)
            ->assertSee('Page not found - Snitch', false);
    }

    public function test_authenticated_dashboard_is_noindex(): void
    {
        $user = User::factory()->create();
        BrandProfile::factory()->for($user)->create();

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('content="noindex, nofollow"', false);
    }

    public function test_sitemap_lists_public_routes_with_metadata(): void
    {
        $response = $this->get(route('sitemap'));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/xml');
        $response->assertSee(route('home', absolute: true), false);
        $response->assertSee(route('pricing', absolute: true), false);
        $response->assertSee(route('how-it-works', absolute: true), false);
        $response->assertSee('<lastmod>', false);
        $response->assertSee('<changefreq>', false);
        $response->assertSee('<priority>1.0</priority>', false);
        $response->assertDontSee('/dashboard', false);
    }

    public function test_robots_txt_blocks_app_paths_and_points_at_absolute_sitemap(): void
    {
        $contents = file_get_contents(public_path('robots.txt'));

        $this->assertNotFalse($contents);
        $this->assertStringContainsString('Sitemap: https://www.snitchsocial.net/sitemap.xml', $contents);
        $this->assertStringContainsString('Disallow: /explore', $contents);
        $this->assertStringContainsString('Disallow: /brand', $contents);
        $this->assertStringContainsString('Disallow: /billing', $contents);
        $this->assertStringContainsString('Disallow: /dashboard', $contents);
        $this->assertStringContainsString('Allow: /', $contents);
    }

    public function test_inertia_shares_seo_prop_on_home(): void
    {
        $this->get(route('home'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Welcome')
                ->has('seo')
                ->where('seo.indexable', true)
                ->where('seo.title', 'Snitch social tracking')
                ->where('seo.robots', 'index, follow')
            );
    }

    public function test_seo_head_renders_json_ld_without_raw_script_tag(): void
    {
        $seoHead = file_get_contents(resource_path('js/components/marketing/SeoHead.vue'));

        $this->assertNotFalse($seoHead);
        $this->assertStringContainsString(":is=\"'script'\"", $seoHead);
        $this->assertStringContainsString('type="application/ld+json"', $seoHead);

        $template = Str::after($seoHead, '<template>');
        $this->assertDoesNotMatchRegularExpression(
            '/<(?!\/)script\b/',
            $template,
            'Vue client templates reject raw <script>; JSON-LD must use <component :is="\'script\'">',
        );
    }
}
