<?php

namespace Tests\Unit\Services\TikHub;

use App\Enums\Platform;
use App\Services\Scraping\YoutubeMediaHydrator;
use App\Services\TikHub\Adapters\YoutubeAdapter;
use App\Services\TikHub\TikHubClient;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

class YoutubeAdapterTest extends TestCase
{
    public function test_search_channels_uses_web_v2_endpoint_without_channel_id(): void
    {
        config([
            'snitch.tikhub.api_key' => 'tikhub-key',
            'snitch.tikhub.base_url' => 'https://api.tikhub.test',
            'snitch.tikhub.endpoints.youtube.search_channels' => '/api/v1/youtube/web_v2/search_channels',
        ]);

        Http::preventStrayRequests();
        Http::fake([
            'https://api.tikhub.test/api/v1/youtube/web_v2/search_channels*' => function ($request) {
                $this->assertStringNotContainsString('channel_id=', $request->url());
                $this->assertStringContainsString('search_query=', $request->url());

                return Http::response([
                    'code' => 200,
                    'data' => [
                        'channels' => [
                            [
                                'channelName' => 'Sneaker Shorts',
                                'channelUsername' => 'sneakershorts',
                                'channelId' => 'UC1234567890123456789012',
                                'subscriberCount' => 12000,
                            ],
                        ],
                    ],
                ]);
            },
        ]);

        $client = app(TikHubClient::class);
        $adapter = new YoutubeAdapter($client, Mockery::mock(YoutubeMediaHydrator::class));

        $rows = $adapter->searchChannels('sneaker Shorts creator', 5);

        $this->assertCount(1, $rows);
        $this->assertSame('sneakershorts', $rows[0]['handle']);
        $this->assertSame(Platform::Youtube->value, $rows[0]['platform']);
        $this->assertSame('tikhub-search', $rows[0]['seed']);
    }
}
