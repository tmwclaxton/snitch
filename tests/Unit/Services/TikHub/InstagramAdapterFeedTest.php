<?php

namespace Tests\Unit\Services\TikHub;

use App\Enums\PostType;
use App\Services\TikHub\Adapters\InstagramAdapter;
use App\Services\TikHub\TikHubClient;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class InstagramAdapterFeedTest extends TestCase
{
    public function test_fetches_posts_and_reels_when_reels_are_all_old(): void
    {
        config([
            'snitch.tikhub.api_key' => 'tikhub-key',
            'snitch.tikhub.base_url' => 'https://api.tikhub.test',
            'snitch.sync.recency_days' => 30,
        ]);

        $postsFixture = $this->fixtureWithRecentDates(
            'instagram_user_posts_carousels.json',
            [
                'CAROUSEL1' => 3,
                'PHOTO1' => 5,
                'HIDDENLIKES1' => 7,
                'DUPEREEL' => 4,
            ],
        );
        $reelsFixture = $this->fixtureWithRecentDates(
            'instagram_user_reels_old_only.json',
            [
                'OLDREEL1' => 60,
                'DUPEREEL' => 4,
            ],
        );

        Http::preventStrayRequests();
        Http::fake([
            'https://api.tikhub.test/api/v1/instagram/v2/fetch_user_posts*' => Http::response($postsFixture),
            'https://api.tikhub.test/api/v1/instagram/v2/fetch_user_reels*' => Http::response($reelsFixture),
        ]);

        $adapter = new InstagramAdapter(app(TikHubClient::class));
        $since = CarbonImmutable::now()->subDays(30);
        $posts = $adapter->listRecentPosts('fuss.london', 20, $since);

        $typesById = collect($posts)->mapWithKeys(
            fn (array $post): array => [(string) $post['external_id'] => $post['type']],
        )->all();

        $this->assertArrayHasKey('CAROUSEL1', $typesById);
        $this->assertSame(PostType::Carousel->value, $typesById['CAROUSEL1']);
        $this->assertArrayHasKey('PHOTO1', $typesById);
        $this->assertSame(PostType::Image->value, $typesById['PHOTO1']);
        $this->assertArrayNotHasKey('OLDREEL1', $typesById);
        $this->assertGreaterThanOrEqual(3, count($posts));
    }

    public function test_merges_feeds_and_dedupes_by_shortcode(): void
    {
        config([
            'snitch.tikhub.api_key' => 'tikhub-key',
            'snitch.tikhub.base_url' => 'https://api.tikhub.test',
            'snitch.sync.recency_days' => 30,
        ]);

        $postsFixture = $this->fixtureWithRecentDates(
            'instagram_user_posts_carousels.json',
            [
                'CAROUSEL1' => 3,
                'PHOTO1' => 5,
                'HIDDENLIKES1' => 7,
                'DUPEREEL' => 4,
            ],
        );
        $reelsFixture = $this->fixtureWithRecentDates(
            'instagram_user_reels_old_only.json',
            [
                'OLDREEL1' => 60,
                'DUPEREEL' => 4,
            ],
        );

        Http::preventStrayRequests();
        Http::fake([
            'https://api.tikhub.test/api/v1/instagram/v2/fetch_user_posts*' => Http::response($postsFixture),
            'https://api.tikhub.test/api/v1/instagram/v2/fetch_user_reels*' => Http::response($reelsFixture),
        ]);

        $adapter = new InstagramAdapter(app(TikHubClient::class));
        $posts = $adapter->listRecentPosts('london.theofflineclub', 20, CarbonImmutable::now()->subDays(30));

        $ids = array_map(fn (array $post): string => (string) $post['external_id'], $posts);
        $this->assertSame(1, count(array_filter($ids, fn (string $id): bool => $id === 'DUPEREEL')));

        $dupe = collect($posts)->firstWhere('external_id', 'DUPEREEL');
        $this->assertIsArray($dupe);
        $this->assertSame(PostType::Reel->value, $dupe['type']);
        $this->assertSame(900, $dupe['metrics']['views']);
    }

    public function test_hidden_likes_are_stored_as_null(): void
    {
        config([
            'snitch.tikhub.api_key' => 'tikhub-key',
            'snitch.tikhub.base_url' => 'https://api.tikhub.test',
            'snitch.sync.recency_days' => 30,
        ]);

        $postsFixture = $this->fixtureWithRecentDates(
            'instagram_user_posts_carousels.json',
            [
                'CAROUSEL1' => 3,
                'PHOTO1' => 5,
                'HIDDENLIKES1' => 7,
                'DUPEREEL' => 4,
            ],
        );
        $reelsFixture = ['code' => 200, 'data' => ['items' => []]];

        Http::preventStrayRequests();
        Http::fake([
            'https://api.tikhub.test/api/v1/instagram/v2/fetch_user_posts*' => Http::response($postsFixture),
            'https://api.tikhub.test/api/v1/instagram/v2/fetch_user_reels*' => Http::response($reelsFixture),
        ]);

        $adapter = new InstagramAdapter(app(TikHubClient::class));
        $posts = $adapter->listRecentPosts('fuss.london', 20, CarbonImmutable::now()->subDays(30));

        $hidden = collect($posts)->firstWhere('external_id', 'HIDDENLIKES1');
        $this->assertIsArray($hidden);
        $this->assertNull($hidden['metrics']['likes']);
        $this->assertTrue($hidden['metrics']['like_count_hidden'] ?? false);
        $this->assertSame(1200, $hidden['metrics']['views']);
        $this->assertSame(7, $hidden['metrics']['comments']);

        $carousel = collect($posts)->firstWhere('external_id', 'CAROUSEL1');
        $this->assertIsArray($carousel);
        $this->assertSame(42, $carousel['metrics']['likes']);
        $this->assertArrayNotHasKey('like_count_hidden', $carousel['metrics']);
    }

    public function test_map_post_types_product_type_clips_as_reel(): void
    {
        $adapter = new InstagramAdapter($this->createMock(TikHubClient::class));
        $method = new \ReflectionMethod(InstagramAdapter::class, 'mapPost');

        $reel = $method->invoke($adapter, [
            'code' => 'CLIP1',
            'media_type' => 2,
            'product_type' => 'clips',
            'taken_at' => now()->subDay()->timestamp,
            'like_count' => 10,
            'comment_count' => 1,
            'play_count' => 500,
            'video_versions' => [['url' => 'https://cdn.example.com/clip.mp4']],
        ], 'demo');

        $this->assertIsArray($reel);
        $this->assertSame(PostType::Reel->value, $reel['type']);
        $this->assertSame('https://www.instagram.com/reel/CLIP1/', $reel['url']);
        $this->assertSame(500, $reel['metrics']['views']);
    }

    /**
     * @param  array<string, int>  $daysAgoByCode
     * @return array<string, mixed>
     */
    private function fixtureWithRecentDates(string $filename, array $daysAgoByCode): array
    {
        /** @var array<string, mixed> $payload */
        $payload = json_decode(
            (string) file_get_contents(base_path('tests/Fixtures/TikHub/'.$filename)),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        $items = data_get($payload, 'data.items', []);

        if (! is_array($items)) {
            return $payload;
        }

        foreach ($items as $index => $item) {
            if (! is_array($item)) {
                continue;
            }

            $media = is_array($item['media'] ?? null) ? $item['media'] : $item;
            $code = (string) ($media['code'] ?? '');

            if ($code === '' || ! array_key_exists($code, $daysAgoByCode)) {
                continue;
            }

            $takenAt = now()->subDays($daysAgoByCode[$code])->timestamp;

            if (isset($item['media']) && is_array($item['media'])) {
                $payload['data']['items'][$index]['media']['taken_at'] = $takenAt;
            } else {
                $payload['data']['items'][$index]['taken_at'] = $takenAt;
            }
        }

        return $payload;
    }
}
