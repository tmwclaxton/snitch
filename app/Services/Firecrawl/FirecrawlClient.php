<?php

namespace App\Services\Firecrawl;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class FirecrawlClient
{
    /**
     * Search the web for ranked results (url, title, description).
     *
     * Supports Firecrawl v1 (`data` as a list) and v2 (`data.web` as a list).
     *
     * @param  array{limit?: int}  $options
     * @return list<array{url: string, title: string, description: string}>
     */
    public function search(string $query, array $options = []): array
    {
        $query = trim($query);

        if ($query === '') {
            throw new RuntimeException('Firecrawl search query must not be empty.');
        }

        $limit = max(1, min(100, (int) ($options['limit'] ?? 10)));

        $response = $this->http()
            ->post('/search', [
                'query' => $query,
                'limit' => $limit,
            ]);

        return $this->hitsFromSearchResponse($response);
    }

    /**
     * Run multiple searches concurrently and merge unique URLs in query order.
     *
     * @param  list<string>  $queries
     * @param  array{limit?: int}  $options
     * @return list<array{url: string, title: string, description: string}>
     */
    public function searchMany(array $queries, array $options = []): array
    {
        $normalized = [];

        foreach ($queries as $query) {
            if (! is_string($query)) {
                continue;
            }

            $query = trim($query);

            if ($query === '' || in_array($query, $normalized, true)) {
                continue;
            }

            $normalized[] = $query;
        }

        if ($normalized === []) {
            return [];
        }

        if (count($normalized) === 1) {
            return $this->search($normalized[0], $options);
        }

        $limit = max(1, min(100, (int) ($options['limit'] ?? 10)));
        $apiKey = (string) config('snitch.firecrawl.api_key');

        if ($apiKey === '') {
            throw new RuntimeException('FIRECRAWL_API_KEY is not configured.');
        }

        $baseUrl = (string) config('snitch.firecrawl.base_url');
        $timeout = (int) config('snitch.firecrawl.timeout', 60);
        $connectTimeout = (int) config('snitch.firecrawl.connect_timeout', 5);

        $responses = Http::pool(function (Pool $pool) use ($normalized, $limit, $baseUrl, $apiKey, $timeout, $connectTimeout): void {
            foreach ($normalized as $index => $query) {
                $pool->as((string) $index)
                    ->baseUrl($baseUrl)
                    ->withToken($apiKey)
                    ->acceptJson()
                    ->timeout($timeout)
                    ->connectTimeout($connectTimeout)
                    ->post('/search', [
                        'query' => $query,
                        'limit' => $limit,
                    ]);
            }
        });

        $hits = [];
        $seen = [];

        foreach ($normalized as $index => $query) {
            $response = $responses[(string) $index] ?? null;

            if (! $response instanceof Response) {
                continue;
            }

            try {
                $rows = $this->hitsFromSearchResponse($response);
            } catch (RuntimeException) {
                continue;
            }

            foreach ($rows as $row) {
                $url = $row['url'];

                if (isset($seen[$url])) {
                    continue;
                }

                $seen[$url] = true;
                $hits[] = $row;
            }
        }

        return $hits;
    }

    /**
     * @return list<array{url: string, title: string, description: string}>
     */
    private function hitsFromSearchResponse(Response $response): array
    {
        if (! $response->successful()) {
            throw new RuntimeException('Firecrawl search failed: '.$response->body());
        }

        $payload = $response->json();

        if (! is_array($payload) || ($payload['success'] ?? false) !== true) {
            throw new RuntimeException('Firecrawl search returned an unsuccessful payload.');
        }

        $rows = $this->searchRowsFromPayload($payload['data'] ?? null);

        return array_values(array_filter(array_map(
            function (mixed $row): ?array {
                if (! is_array($row)) {
                    return null;
                }

                $url = isset($row['url']) && is_string($row['url']) ? trim($row['url']) : '';

                if ($url === '') {
                    return null;
                }

                return [
                    'url' => $url,
                    'title' => isset($row['title']) && is_string($row['title']) ? trim($row['title']) : '',
                    'description' => isset($row['description']) && is_string($row['description'])
                        ? trim($row['description'])
                        : '',
                ];
            },
            $rows,
        )));
    }

    /**
     * @return list<mixed>
     */
    private function searchRowsFromPayload(mixed $data): array
    {
        if (! is_array($data)) {
            return [];
        }

        if (array_is_list($data)) {
            return $data;
        }

        $web = $data['web'] ?? null;

        return is_array($web) ? array_values($web) : [];
    }

    /**
     * Scrape a URL for markdown, outbound links, summary, and page metadata.
     *
     * @param  list<string>  $formats
     * @return array{markdown: ?string, summary: ?string, links: list<string>, metadata: array<string, mixed>}
     */
    public function scrape(string $url, array $formats = ['markdown', 'links', 'summary']): array
    {
        $response = $this->http()
            ->post('/scrape', [
                'url' => $url,
                'formats' => $formats,
                // Footer/nav social links are usually outside "main" content.
                'onlyMainContent' => false,
            ]);

        if (! $response->successful()) {
            throw new RuntimeException('Firecrawl scrape failed: '.$response->body());
        }

        $payload = $response->json();

        if (! is_array($payload) || ($payload['success'] ?? false) !== true) {
            throw new RuntimeException('Firecrawl scrape returned an unsuccessful payload.');
        }

        $data = $payload['data'] ?? null;

        if (! is_array($data)) {
            throw new RuntimeException('Firecrawl scrape returned unexpected data.');
        }

        $links = $data['links'] ?? [];

        if (! is_array($links)) {
            $links = [];
        }

        /** @var list<string> $normalizedLinks */
        $normalizedLinks = array_values(array_filter(
            array_map(static fn (mixed $link): ?string => is_string($link) ? $link : null, $links),
        ));

        $metadata = $data['metadata'] ?? [];

        if (! is_array($metadata)) {
            $metadata = [];
        }

        return [
            'markdown' => isset($data['markdown']) && is_string($data['markdown']) ? $data['markdown'] : null,
            'summary' => isset($data['summary']) && is_string($data['summary']) ? $data['summary'] : null,
            'links' => $normalizedLinks,
            'metadata' => $metadata,
        ];
    }

    protected function http(): PendingRequest
    {
        $apiKey = (string) config('snitch.firecrawl.api_key');

        if ($apiKey === '') {
            throw new RuntimeException('FIRECRAWL_API_KEY is not configured.');
        }

        return Http::baseUrl((string) config('snitch.firecrawl.base_url'))
            ->withToken($apiKey)
            ->acceptJson()
            ->timeout((int) config('snitch.firecrawl.timeout', 60))
            ->connectTimeout((int) config('snitch.firecrawl.connect_timeout', 5))
            ->retry(2, 200, throw: false);
    }
}
