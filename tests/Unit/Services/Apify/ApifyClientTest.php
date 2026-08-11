<?php

namespace Tests\Unit\Services\Apify;

use App\Services\Apify\ApifyClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ApifyClientTest extends TestCase
{
    public function test_run_actor_sends_bearer_token_not_query_token(): void
    {
        config([
            'snitch.apify.token' => 'secret-apify-token',
            'snitch.apify.base_url' => 'https://api.apify.test/v2',
            'snitch.apify.timeout' => 30,
        ]);

        Http::preventStrayRequests();
        Http::fake([
            'https://api.apify.test/v2/acts/*/runs*' => Http::response([
                'data' => [
                    'id' => 'run_1',
                    'defaultDatasetId' => 'dataset_1',
                    'usageTotalUsd' => 0.02,
                ],
            ]),
            'https://api.apify.test/v2/datasets/dataset_1/items*' => Http::response([
                ['id' => '1'],
            ]),
            'https://api.apify.test/v2/actor-runs/run_1' => Http::response([
                'data' => [
                    'id' => 'run_1',
                    'defaultDatasetId' => 'dataset_1',
                    'usageTotalUsd' => 0.02,
                ],
            ]),
        ]);

        $client = app(ApifyClient::class);
        $items = $client->runActor('apify/instagram-scraper', [
            'username' => ['demo'],
        ]);

        $this->assertSame([['id' => '1']], $items);
        $costs = $client->pullRunCosts();
        $this->assertSame(0.02, $costs[0]['usageTotalUsd'] ?? null);

        Http::assertSent(function ($request): bool {
            $hasBearer = $request->hasHeader('Authorization')
                && $request->header('Authorization')[0] === 'Bearer secret-apify-token';
            $query = parse_url($request->url(), PHP_URL_QUERY) ?: '';
            parse_str($query, $params);

            return $hasBearer && ! array_key_exists('token', $params);
        });
    }

    public function test_run_actors_soft_fails_single_connection_exception(): void
    {
        config([
            'snitch.apify.token' => 'secret-apify-token',
            'snitch.apify.base_url' => 'https://api.apify.test/v2',
        ]);

        Http::preventStrayRequests();
        Http::fake(function () {
            throw new ConnectionException('cURL error 28: SSL connection timeout');
        });

        $items = app(ApifyClient::class)->runActors([
            'fb' => [
                'actorId' => 'apify/facebook-posts-scraper',
                'input' => ['startUrls' => [['url' => 'https://facebook.com/demo']]],
            ],
        ]);

        $this->assertSame(['fb' => []], $items);
    }

    public function test_run_actors_refreshes_preliminary_zero_usage(): void
    {
        config([
            'snitch.apify.token' => 'secret-apify-token',
            'snitch.apify.base_url' => 'https://api.apify.test/v2',
            'snitch.apify.timeout' => 30,
        ]);

        Http::preventStrayRequests();
        Http::fake([
            'https://api.apify.test/v2/acts/*/runs*' => Http::sequence()
                ->push([
                    'data' => [
                        'id' => 'run_a',
                        'defaultDatasetId' => 'dataset_a',
                        'usageTotalUsd' => 0,
                    ],
                ])
                ->push([
                    'data' => [
                        'id' => 'run_b',
                        'defaultDatasetId' => 'dataset_b',
                        'usageTotalUsd' => 0,
                    ],
                ]),
            'https://api.apify.test/v2/datasets/dataset_a/items*' => Http::response([['id' => 'a']]),
            'https://api.apify.test/v2/datasets/dataset_b/items*' => Http::response([['id' => 'b']]),
            'https://api.apify.test/v2/actor-runs/run_a' => Http::response([
                'data' => [
                    'id' => 'run_a',
                    'defaultDatasetId' => 'dataset_a',
                    'usageTotalUsd' => 0.0023,
                ],
            ]),
            'https://api.apify.test/v2/actor-runs/run_b' => Http::response([
                'data' => [
                    'id' => 'run_b',
                    'defaultDatasetId' => 'dataset_b',
                    'usageTotalUsd' => 0.0041,
                ],
            ]),
        ]);

        $client = app(ApifyClient::class);
        $items = $client->runActors([
            'a' => [
                'actorId' => 'apify/instagram-scraper',
                'input' => ['directUrls' => ['https://instagram.com/a']],
            ],
            'b' => [
                'actorId' => 'apify/instagram-scraper',
                'input' => ['directUrls' => ['https://instagram.com/b']],
            ],
        ]);

        $this->assertSame([['id' => 'a']], $items['a']);
        $this->assertSame([['id' => 'b']], $items['b']);

        $costs = $client->pullRunCosts();
        $this->assertCount(2, $costs);
        $byRun = collect($costs)->keyBy('runId');
        $this->assertSame(0.0023, $byRun['run_a']['usageTotalUsd'] ?? null);
        $this->assertSame(0.0041, $byRun['run_b']['usageTotalUsd'] ?? null);
    }
}
