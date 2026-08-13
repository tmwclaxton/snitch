<?php

namespace Tests\Unit\Services\TikHub;

use App\Services\TikHub\Adapters\InstagramAdapter;
use App\Services\TikHub\TikHubClient;
use Tests\TestCase;

class InstagramAdapterTest extends TestCase
{
    public function test_map_profile_reads_nested_v2_user_info_payload(): void
    {
        $payload = json_decode(
            file_get_contents(base_path('tests/Fixtures/TikHub/instagram_user_info.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        $client = $this->createMock(TikHubClient::class);
        $adapter = new InstagramAdapter($client);

        $method = new \ReflectionMethod(InstagramAdapter::class, 'mapProfile');
        $profile = $method->invoke($adapter, $payload['data'], 'vanessalau');

        $this->assertSame('vanessalau', $profile['handle']);
        $this->assertSame('93872', $profile['external_id']);
        $this->assertSame(418, $profile['followers']);
        $this->assertSame('Vanessa', $profile['display_name']);
    }
}
