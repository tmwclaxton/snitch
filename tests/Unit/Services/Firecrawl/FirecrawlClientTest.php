<?php

namespace Tests\Unit\Services\Firecrawl;

use App\Services\Firecrawl\FirecrawlClient;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class FirecrawlClientTest extends TestCase
{
    public function test_search_returns_normalized_hits_from_v1_list(): void
    {
        config([
            'snitch.firecrawl.api_key' => 'test-key',
            'snitch.firecrawl.base_url' => 'https://api.firecrawl.test/v1',
        ]);

        Http::preventStrayRequests();
        Http::fake([
            'https://api.firecrawl.test/v1/search' => Http::response([
                'success' => true,
                'data' => [
                    [
                        'url' => 'https://instrumentl.com',
                        'title' => 'Instrumentl',
                        'description' => 'Grant discovery platform',
                    ],
                    [
                        'url' => 'https://example.com',
                        'title' => null,
                        'description' => null,
                    ],
                ],
            ]),
        ]);

        $result = app(FirecrawlClient::class)->search('grant tools competitors', ['limit' => 5]);

        $this->assertSame([
            [
                'url' => 'https://instrumentl.com',
                'title' => 'Instrumentl',
                'description' => 'Grant discovery platform',
            ],
            [
                'url' => 'https://example.com',
                'title' => '',
                'description' => '',
            ],
        ], $result);

        Http::assertSent(function ($request): bool {
            return str_ends_with($request->url(), '/search')
                && ($request['query'] ?? null) === 'grant tools competitors'
                && ($request['limit'] ?? null) === 5;
        });
    }

    public function test_search_returns_hits_from_v2_web_group(): void
    {
        config([
            'snitch.firecrawl.api_key' => 'test-key',
            'snitch.firecrawl.base_url' => 'https://api.firecrawl.test/v2',
        ]);

        Http::preventStrayRequests();
        Http::fake([
            'https://api.firecrawl.test/v2/search' => Http::response([
                'success' => true,
                'data' => [
                    'web' => [
                        [
                            'url' => 'https://grantwatch.com',
                            'title' => 'GrantWatch',
                            'description' => 'Grant listings',
                        ],
                    ],
                ],
            ]),
        ]);

        $result = app(FirecrawlClient::class)->search('grantwatch');

        $this->assertCount(1, $result);
        $this->assertSame('https://grantwatch.com', $result[0]['url']);
    }

    public function test_search_requires_api_key(): void
    {
        config([
            'snitch.firecrawl.api_key' => '',
            'snitch.firecrawl.base_url' => 'https://api.firecrawl.test/v1',
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('FIRECRAWL_API_KEY is not configured.');

        app(FirecrawlClient::class)->search('anything');
    }

    public function test_search_many_merges_and_dedupes_urls_in_query_order(): void
    {
        config([
            'snitch.firecrawl.api_key' => 'test-key',
            'snitch.firecrawl.base_url' => 'https://api.firecrawl.test/v1',
        ]);

        Http::preventStrayRequests();
        Http::fake([
            'https://api.firecrawl.test/v1/search' => Http::sequence()
                ->push([
                    'success' => true,
                    'data' => [
                        [
                            'url' => 'https://shared.example',
                            'title' => 'Shared A',
                            'description' => 'First query',
                        ],
                        [
                            'url' => 'https://only-a.example',
                            'title' => 'Only A',
                            'description' => 'A',
                        ],
                    ],
                ])
                ->push([
                    'success' => true,
                    'data' => [
                        [
                            'url' => 'https://shared.example',
                            'title' => 'Shared B',
                            'description' => 'Second query duplicate',
                        ],
                        [
                            'url' => 'https://only-b.example',
                            'title' => 'Only B',
                            'description' => 'B',
                        ],
                    ],
                ]),
        ]);

        $result = app(FirecrawlClient::class)->searchMany(
            ['grant tools', 'grant competitors', 'grant tools'],
            ['limit' => 5],
        );

        $this->assertSame([
            'https://shared.example',
            'https://only-a.example',
            'https://only-b.example',
        ], array_column($result, 'url'));
        $this->assertSame('Shared A', $result[0]['title']);
        Http::assertSentCount(2);
    }

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
