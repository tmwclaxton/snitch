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
        ]);

        Http::preventStrayRequests();
        Http::fake([
            'https://api.apify.test/v2/acts/*' => Http::response([
                ['id' => '1'],
            ]),
        ]);

        $items = app(ApifyClient::class)->runActor('apify/instagram-scraper', [
            'username' => ['demo'],
        ]);

        $this->assertSame([['id' => '1']], $items);

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
}
