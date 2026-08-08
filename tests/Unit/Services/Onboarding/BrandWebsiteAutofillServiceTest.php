<?php

namespace Tests\Unit\Services\Onboarding;

use App\Services\Analysis\NanoGptClient;
use App\Services\Onboarding\BrandWebsiteAutofillService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BrandWebsiteAutofillServiceTest extends TestCase
{
    public function test_grantgunner_fixture_yields_clean_brand_description(): void
    {
        config([
            'snitch.firecrawl.api_key' => 'test-key',
            'snitch.firecrawl.base_url' => 'https://api.firecrawl.test/v1',
            'snitch.nanogpt.api_key' => '',
        ]);

        $fixture = json_decode(
            (string) file_get_contents(base_path('tests/fixtures/firecrawl/grantgunner.org.json')),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        Http::preventStrayRequests();
        Http::fake([
            'https://api.firecrawl.test/v1/scrape' => Http::response($fixture),
        ]);

        $fields = app(BrandWebsiteAutofillService::class)->extract('https://www.grantgunner.org');

        $description = $fields['description'];

        $this->assertIsString($description);
        $this->assertNotSame('', trim($description));
        $this->assertDoesNotMatchRegularExpression('/^welcome\s+to\b/i', $description);
        $this->assertStringNotContainsString(', Your AI agent', $description);
        $this->assertStringNotContainsString('your profile, your review, your submit., Your', $description);
        $this->assertGreaterThanOrEqual(80, strlen($description));
        $this->assertLessThanOrEqual(1000, strlen($description));
        $this->assertMatchesRegularExpression('/[.!?]/', $description);
        $this->assertTrue(
            str_contains(strtolower($description), 'grant')
                || str_contains(strtolower($description), 'funding'),
            'Description should mention grants or funding',
        );
        $this->assertSame('GrantGunner', $fields['name']);
        $this->assertSame('@grantgunner_official', $fields['own_handles']['instagram']);
        $this->assertSame('@grantgunner', $fields['own_handles']['linkedin']);
        $this->assertSame('@61588602791318', $fields['own_handles']['facebook']);
        $this->assertNull($fields['own_handles']['tiktok']);
        $this->assertNull($fields['own_handles']['youtube']);
        $this->assertArrayNotHasKey('pinterest', $fields['own_handles']);
    }

    public function test_extracts_social_handles_from_footer_markdown_when_links_array_empty(): void
    {
        config([
            'snitch.firecrawl.api_key' => 'test-key',
            'snitch.firecrawl.base_url' => 'https://api.firecrawl.test/v1',
            'snitch.nanogpt.api_key' => '',
        ]);

        Http::preventStrayRequests();
        Http::fake([
            'https://api.firecrawl.test/v1/scrape' => Http::response([
                'success' => true,
                'data' => [
                    'markdown' => "# Brand\n\nWe help makers ship content weekly with a clear weekly plan.\n\n[Instagram](https://www.instagram.com/acme.studio/)[LinkedIn](https://www.linkedin.com/company/acme-studio/)[Facebook](https://www.facebook.com/profile.php?id=1234567890)",
                    'summary' => 'We help makers ship content weekly with a clear weekly plan for small studios.',
                    'links' => [],
                    'metadata' => [
                        'title' => 'Acme Studio',
                        'ogSiteName' => 'Acme Studio',
                    ],
                ],
            ]),
        ]);

        $fields = app(BrandWebsiteAutofillService::class)->extract('https://acme.example');

        $this->assertSame('@acme.studio', $fields['own_handles']['instagram']);
        $this->assertSame('@acme-studio', $fields['own_handles']['linkedin']);
        $this->assertSame('@1234567890', $fields['own_handles']['facebook']);
    }

    public function test_rejects_seo_meta_dump_in_favor_of_summary(): void
    {
        config([
            'snitch.firecrawl.api_key' => 'test-key',
            'snitch.firecrawl.base_url' => 'https://api.firecrawl.test/v1',
            'snitch.nanogpt.api_key' => '',
        ]);

        Http::preventStrayRequests();
        Http::fake([
            'https://api.firecrawl.test/v1/scrape' => Http::response([
                'success' => true,
                'data' => [
                    'markdown' => "# Brand\n\nWelcome to our homepage. Click here.",
                    'summary' => 'We help neighborhood bakeries plan content that sells more loaves each week. Our studio pairs taste tests with short-form video coaching.',
                    'links' => [],
                    'metadata' => [
                        'title' => 'Brand | Home',
                        'description' => 'Buy bread now., Your bakery partner for growth and SEO keywords galore.',
                    ],
                ],
            ]),
        ]);

        $fields = app(BrandWebsiteAutofillService::class)->extract('https://bakery.example');

        $this->assertStringContainsString('neighborhood bakeries', (string) $fields['description']);
        $this->assertStringNotContainsString('Buy bread now., Your', (string) $fields['description']);
        $this->assertDoesNotMatchRegularExpression('/^welcome\s+to\b/i', (string) $fields['description']);
    }

    public function test_uses_nanogpt_rewrite_when_heuristics_are_weak(): void
    {
        config([
            'snitch.firecrawl.api_key' => 'test-key',
            'snitch.firecrawl.base_url' => 'https://api.firecrawl.test/v1',
            'snitch.nanogpt.api_key' => 'nano-key',
            'snitch.nanogpt.base_url' => 'https://nano.test/api/v1',
            'snitch.brand_autofill.model' => 'deepseek/deepseek-v4-flash',
        ]);

        Http::preventStrayRequests();
        Http::fake([
            'https://api.firecrawl.test/v1/scrape' => Http::response([
                'success' => true,
                'data' => [
                    'markdown' => "# Shop\n\nHome About Contact Blog Pricing",
                    'summary' => null,
                    'links' => [],
                    'metadata' => [
                        'title' => 'Shop',
                        'description' => 'Welcome to Shop',
                    ],
                ],
            ]),
            'https://nano.test/api/v1/chat/completions' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'content' => 'We make small-batch ceramics for everyday tables. Our studio ships pieces that feel handmade without the precious price tag.',
                        ],
                    ],
                ],
            ]),
        ]);

        $fields = app(BrandWebsiteAutofillService::class)->extract('https://shop.example');

        $this->assertSame(
            'We make small-batch ceramics for everyday tables. Our studio ships pieces that feel handmade without the precious price tag.',
            $fields['description'],
        );

        Http::assertSent(function ($request): bool {
            return str_contains($request->url(), 'chat/completions')
                && ($request['model'] ?? null) === 'deepseek/deepseek-v4-flash';
        });
    }

    public function test_skips_nanogpt_when_api_key_missing(): void
    {
        config([
            'snitch.firecrawl.api_key' => 'test-key',
            'snitch.firecrawl.base_url' => 'https://api.firecrawl.test/v1',
            'snitch.nanogpt.api_key' => '',
        ]);

        Http::preventStrayRequests();
        Http::fake([
            'https://api.firecrawl.test/v1/scrape' => Http::response([
                'success' => true,
                'data' => [
                    'markdown' => "# Shop\n\nHome About Contact",
                    'links' => [],
                    'metadata' => [
                        'title' => 'Shop',
                        'description' => 'Welcome to Shop',
                    ],
                ],
            ]),
        ]);

        $nano = $this->mock(NanoGptClient::class);
        $nano->shouldNotReceive('chat');

        $fields = app(BrandWebsiteAutofillService::class)->extract('https://shop.example');

        $this->assertNull($fields['description']);
    }
}
