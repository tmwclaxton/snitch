<?php

namespace App\Services\Firecrawl;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class FirecrawlClient
{
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
