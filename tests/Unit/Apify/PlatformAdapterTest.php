<?php

namespace Tests\Unit\Apify;

use App\Services\Apify\Adapters\FacebookAdapter;
use App\Services\Apify\Adapters\InstagramAdapter;
use App\Services\Apify\Adapters\LinkedInAdapter;
use App\Services\Apify\Adapters\TikTokAdapter;
use App\Services\Apify\ApifyClient;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PlatformAdapterTest extends TestCase
{
    /**
     * @return array<string, array{0: class-string, 1: string}>
     */
    public static function adapters(): array
    {
        return [
            'instagram' => [InstagramAdapter::class, 'instagram.json'],
            'tiktok' => [TikTokAdapter::class, 'tiktok.json'],
            'facebook' => [FacebookAdapter::class, 'facebook.json'],
            'linkedin' => [LinkedInAdapter::class, 'linkedin.json'],
        ];
    }

    #[DataProvider('adapters')]
    public function test_adapter_maps_fixture_to_normalized_post_shape(string $adapterClass, string $fixture): void
    {
        $client = $this->createMock(ApifyClient::class);
        /** @var InstagramAdapter|TikTokAdapter|FacebookAdapter|LinkedInAdapter $adapter */
        $adapter = new $adapterClass($client);

        $items = json_decode(
            (string) file_get_contents(base_path('tests/Fixtures/Apify/'.$fixture)),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        $posts = $adapter->mapFixturePosts($items, 'rivalbakery');

        $this->assertNotEmpty($posts);
        $post = $posts[0];

        foreach (['external_id', 'url', 'posted_at', 'type', 'caption', 'media_url', 'metrics', 'raw_payload'] as $field) {
            $this->assertArrayHasKey($field, $post);
        }

        $this->assertNotSame('', (string) $post['external_id']);
        $this->assertNotSame('', (string) $post['url']);
        $this->assertNotNull($post['posted_at']);
        $this->assertNotSame('', (string) $post['type']);
        $this->assertIsArray($post['metrics']);
        $this->assertIsArray($post['raw_payload']);
    }
}
