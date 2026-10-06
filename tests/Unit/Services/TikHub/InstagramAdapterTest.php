<?php

namespace Tests\Unit\Services\TikHub;

use App\Enums\Platform;
use App\Models\SocialAccount;
use App\Services\TikHub\Adapters\InstagramAdapter;
use App\Services\TikHub\TikHubClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class InstagramAdapterTest extends TestCase
{
    use RefreshDatabase;

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

    public function test_resolve_profile_falls_back_to_v1_username_route(): void
    {
        $payload = json_decode(
            file_get_contents(base_path('tests/Fixtures/TikHub/instagram_user_info.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        SocialAccount::factory()->forPlatform(Platform::Instagram)->create([
            'handle' => 'vanessalau',
            'external_id' => '93872',
        ]);

        $client = $this->createMock(TikHubClient::class);
        $client->expects($this->exactly(3))
            ->method('get')
            ->willReturnCallback(function (string $path, array $query) use ($payload): array {
                if ($path === '/api/v1/instagram/v2/fetch_user_info') {
                    throw new RuntimeException('TikHub request failed (400): Request failed. Please retry');
                }

                $this->assertSame('/api/v1/instagram/v1/fetch_user_info_by_username_v2', $path);
                $this->assertSame(['username' => 'vanessalau'], $query);

                return $payload;
            });

        $profile = (new InstagramAdapter($client))->resolveProfile('vanessalau');

        $this->assertSame(418, $profile['followers']);
        $this->assertSame('vanessalau', $profile['handle']);
    }
}
