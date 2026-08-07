<?php

namespace Tests\Unit\Services\Firecrawl;

use App\Services\Firecrawl\FirecrawlClient;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class FirecrawlClientTest extends TestCase
{
    public function test_scrape_returns_markdown_links_and_metadata(): void
    {
        config([
            'snitch.firecrawl.api_key' => 'test-key',
            'snitch.firecrawl.base_url' => 'https://api.firecrawl.test/v1',
        ]);

        Http::preventStrayRequests();
        Http::fake([
            'https://api.firecrawl.test/v1/scrape' => Http::response([
                'success' => true,
                'data' => [
                    'markdown' => '# Hello',
                    'links' => ['https://instagram.com/brand'],
                    'metadata' => ['title' => 'Brand'],
                ],
            ]),
        ]);

        $result = app(FirecrawlClient::class)->scrape('https://brand.example');

        $this->assertSame('# Hello', $result['markdown']);
        $this->assertNull($result['summary']);
        $this->assertSame(['https://instagram.com/brand'], $result['links']);
        $this->assertSame('Brand', $result['metadata']['title']);

        Http::assertSent(function ($request): bool {
            return ($request['onlyMainContent'] ?? null) === false
                && in_array('links', $request['formats'] ?? [], true);
        });
    }

    public function test_scrape_returns_summary_when_present(): void
    {
        config([
            'snitch.firecrawl.api_key' => 'test-key',
            'snitch.firecrawl.base_url' => 'https://api.firecrawl.test/v1',
        ]);

        Http::preventStrayRequests();
        Http::fake([
            'https://api.firecrawl.test/v1/scrape' => Http::response([
                'success' => true,
                'data' => [
                    'markdown' => '# Hello',
                    'summary' => 'A short brand summary.',
                    'links' => [],
                    'metadata' => [],
                ],
            ]),
        ]);

        $result = app(FirecrawlClient::class)->scrape('https://brand.example');

        $this->assertSame('A short brand summary.', $result['summary']);

        Http::assertSent(function ($request): bool {
            return in_array('summary', $request['formats'] ?? [], true)
                && in_array('markdown', $request['formats'] ?? [], true)
                && in_array('links', $request['formats'] ?? [], true);
        });
    }

    public function test_scrape_requires_api_key(): void
    {
        config([
            'snitch.firecrawl.api_key' => '',
            'snitch.firecrawl.base_url' => 'https://api.firecrawl.test/v1',
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('FIRECRAWL_API_KEY is not configured.');

        app(FirecrawlClient::class)->scrape('https://brand.example');
    }

    public function test_scrape_throws_on_unsuccessful_response(): void
    {
        config([
            'snitch.firecrawl.api_key' => 'test-key',
            'snitch.firecrawl.base_url' => 'https://api.firecrawl.test/v1',
        ]);

        Http::preventStrayRequests();
        Http::fake([
            'https://api.firecrawl.test/v1/scrape' => Http::response(['success' => false], 500),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Firecrawl scrape failed:');

        app(FirecrawlClient::class)->scrape('https://brand.example');
    }
}
