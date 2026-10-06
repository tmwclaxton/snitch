<?php

namespace Tests\Feature;

use App\Enums\Platform;
use App\Enums\PostType;
use App\Models\BrandProfile;
use App\Models\FollowerSnapshot;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\TrackedAccount;
use App\Models\User;
use App\Services\Apify\Adapters\InstagramAdapter as ApifyInstagramAdapter;
use App\Services\Apify\Contracts\PlatformAdapter;
use App\Services\Apify\PlatformAdapterManager;
use App\Services\Competitors\CompetitorInsightsBuilder;
use App\Services\TikHub\Adapters\InstagramAdapter as TikHubInstagramAdapter;
use App\Services\TikHub\TikHubClient;
use App\Services\Tracking\FollowerCountRefresher;
use App\Services\Tracking\FollowerSnapshotRecorder;
use App\Support\InstagramPostId;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class EngagementDataQualityTest extends TestCase
{
    use RefreshDatabase;

    public function test_apify_reel_prefers_play_count_over_legacy_view_count(): void
    {
        $adapter = app(ApifyInstagramAdapter::class);
        $method = new \ReflectionMethod(ApifyInstagramAdapter::class, 'mapPost');

        $mapped = $method->invoke($adapter, [
            'id' => '9876543210',
            'shortCode' => 'YELLOW1',
            'url' => 'https://www.instagram.com/reel/YELLOW1/',
            'type' => 'Video',
            'productType' => 'clips',
            'videoUrl' => 'https://cdn.example.com/reel.mp4',
            'videoViewCount' => 4,
            'videoPlayCount' => 583,
            'likesCount' => 39,
            'commentsCount' => 4,
        ], 'yellowzest');

        $this->assertIsArray($mapped);
        $this->assertSame('YELLOW1', $mapped['external_id']);
        $this->assertSame(583, $mapped['metrics']['views']);
        $this->assertSame(PostType::Reel->value, $mapped['type']);
    }

    public function test_tikhub_and_apify_instagram_posts_share_shortcode_external_id(): void
    {
        $apify = app(ApifyInstagramAdapter::class);
        $apifyMap = new \ReflectionMethod(ApifyInstagramAdapter::class, 'mapPost');
        $apifyPost = $apifyMap->invoke($apify, [
            'id' => '111222333',
            'shortCode' => 'SAMECODE',
            'url' => 'https://www.instagram.com/reel/SAMECODE/',
            'type' => 'Video',
            'productType' => 'clips',
            'videoUrl' => 'https://cdn.example.com/a.mp4',
            'videoPlayCount' => 900,
            'likesCount' => 40,
            'commentsCount' => 2,
        ], 'yellowzest');

        $tikhub = new TikHubInstagramAdapter($this->createMock(TikHubClient::class));
        $tikhubMap = new \ReflectionMethod(TikHubInstagramAdapter::class, 'mapPost');
        $tikhubPost = $tikhubMap->invoke($tikhub, [
            'media' => [
                'pk' => '111222333',
                'code' => 'SAMECODE',
                'product_type' => 'clips',
                'video_url' => 'https://cdn.example.com/b.mp4',
                'play_count' => 950,
                'like_count' => 41,
                'comment_count' => 3,
            ],
        ], 'yellowzest');

        $this->assertSame('SAMECODE', $apifyPost['external_id']);
        $this->assertSame('SAMECODE', $tikhubPost['external_id']);
        $this->assertSame(
            InstagramPostId::fromUrl('https://www.instagram.com/reel/SAMECODE/'),
            $apifyPost['external_id'],
        );
    }

    public function test_engagement_rate_uses_ratio_of_sums_and_ignores_impossible_posts(): void
    {
        $user = User::factory()->create();
        BrandProfile::factory()->for($user)->create();
        $account = TrackedAccount::factory()->for($user)->create();

        // Broken Apify-style row: legacy 3-second views below interactions.
        Post::factory()->forAccount($account)->create([
            'type' => PostType::Reel,
            'posted_at' => now()->subDay(),
            'metrics' => [
                'views' => 4,
                'likes' => 39,
                'comments' => 4,
                'shares' => 0,
            ],
        ]);
        Post::factory()->forAccount($account)->create([
            'type' => PostType::Reel,
            'posted_at' => now()->subHours(6),
            'metrics' => [
                'views' => 1000,
                'likes' => 50,
                'comments' => 5,
                'shares' => 5,
            ],
        ]);
        Post::factory()->forAccount($account)->create([
            'type' => PostType::Reel,
            'posted_at' => now()->subHours(3),
            'metrics' => [
                'views' => 500,
                'likes' => 20,
                'comments' => 2,
                'shares' => 2,
            ],
        ]);

        $insights = app(CompetitorInsightsBuilder::class)->forAccount($user, $account);

        // Only the two valid posts: (60 + 24) / 1500 = 5.6%
        $this->assertSame(5.6, $insights['engagement']['avg_rate']);
        $this->assertLessThan(100.0, $insights['engagement']['avg_rate']);
    }

    public function test_yellowzest_fixture_engagement_matches_expected_after_view_fix(): void
    {
        $user = User::factory()->create();
        BrandProfile::factory()->for($user)->create();
        $account = TrackedAccount::factory()->for($user)->create([
            'handle' => 'yellowzest',
            'followers' => 1158,
        ]);

        $rows = [
            // Fixed Apify play counts (previously stored as 4,7,10,88,183,181,101)
            ['views' => 583, 'likes' => 39, 'comments' => 4, 'shares' => 0],
            ['views' => 420, 'likes' => 22, 'comments' => 3, 'shares' => 0],
            ['views' => 423, 'likes' => 25, 'comments' => 3, 'shares' => 0],
            ['views' => 2640, 'likes' => 82, 'comments' => 1, 'shares' => 0],
            ['views' => 550, 'likes' => 51, 'comments' => 6, 'shares' => 0],
            ['views' => 570, 'likes' => 39, 'comments' => 6, 'shares' => 0],
            ['views' => 411, 'likes' => 21, 'comments' => 0, 'shares' => 0],
            // Representative TikHub rows after dedupe (no Apify duplicates)
            ['views' => 800, 'likes' => 40, 'comments' => 4, 'shares' => 2],
            ['views' => 600, 'likes' => 30, 'comments' => 3, 'shares' => 1],
            ['views' => 450, 'likes' => 18, 'comments' => 2, 'shares' => 0],
            ['views' => 700, 'likes' => 35, 'comments' => 5, 'shares' => 1],
            ['views' => 520, 'likes' => 22, 'comments' => 1, 'shares' => 0],
            ['views' => 480, 'likes' => 19, 'comments' => 2, 'shares' => 1],
            ['views' => 910, 'likes' => 48, 'comments' => 6, 'shares' => 2],
            ['views' => 330, 'likes' => 14, 'comments' => 1, 'shares' => 0],
            ['views' => 760, 'likes' => 33, 'comments' => 4, 'shares' => 1],
        ];

        foreach ($rows as $metrics) {
            Post::factory()->forAccount($account)->create([
                'type' => PostType::Reel,
                'posted_at' => now()->subDays(rand(1, 20)),
                'metrics' => $metrics,
            ]);
        }

        $insights = app(CompetitorInsightsBuilder::class)->forAccount($user, $account);
        $totalViews = array_sum(array_column($rows, 'views'));
        $totalInteractions = array_sum(array_map(
            fn (array $row): int => $row['likes'] + $row['comments'] + $row['shares'],
            $rows,
        ));
        $expected = round(($totalInteractions / $totalViews) * 100, 2);

        $this->assertSame($expected, $insights['engagement']['avg_rate']);
        $this->assertGreaterThan(3.0, $insights['engagement']['avg_rate']);
        $this->assertLessThan(8.0, $insights['engagement']['avg_rate']);
    }

    public function test_second_tracker_inherits_follower_count_when_refresh_is_skipped(): void
    {
        $social = SocialAccount::factory()->forPlatform(Platform::Instagram)->create([
            'handle' => 'yellowzest',
        ]);
        $owner = User::factory()->create();
        $other = User::factory()->create();

        TrackedAccount::factory()->for($owner)->forSocialAccount($social)->create([
            'followers' => 1158,
        ]);
        FollowerSnapshot::factory()->create([
            'social_account_id' => $social->id,
            'followers' => 1158,
            'captured_on' => now()->toDateString(),
        ]);

        $second = TrackedAccount::factory()->for($other)->forSocialAccount($social)->create([
            'followers' => null,
        ]);

        $this->assertSame(1158, $second->fresh()?->followers);

        $adapter = Mockery::mock(PlatformAdapter::class);
        $adapter->shouldReceive('resolveProfile')->never();
        $manager = Mockery::mock(PlatformAdapterManager::class);
        $manager->shouldReceive('for')->never();
        $this->app->instance(PlatformAdapterManager::class, $manager);

        $this->assertFalse(app(FollowerCountRefresher::class)->refresh($social->fresh()));
        $this->assertSame(1158, $second->fresh()?->followers);
    }

    public function test_snapshot_recorder_does_not_invent_week_or_month_baselines(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-22 12:00:00'));

        try {
            $account = TrackedAccount::factory()->create([
                'followers' => 1158,
            ]);
            app(FollowerSnapshotRecorder::class)->record((int) $account->social_account_id, (int) $account->followers);

            $this->assertSame(1, FollowerSnapshot::query()->count());
            $this->assertFalse(
                FollowerSnapshot::query()
                    ->whereDate('captured_on', '2026-09-15')
                    ->exists(),
            );
            $this->assertFalse(
                FollowerSnapshot::query()
                    ->whereDate('captured_on', '2026-08-23')
                    ->exists(),
            );

            $user = User::factory()->create();
            BrandProfile::factory()->for($user)->create();
            $tracked = TrackedAccount::factory()->for($user)->create([
                'followers' => 55760,
            ]);
            FollowerSnapshot::factory()->create([
                'social_account_id' => $tracked->social_account_id,
                'followers' => 55760,
                'captured_on' => now()->toDateString(),
            ]);

            $insights = app(CompetitorInsightsBuilder::class)->forAccount($user, $tracked);
            $this->assertNull($insights['growth']['week_delta']);
            $this->assertCount(1, $insights['follower_series']);
        } finally {
            CarbonImmutable::setTestNow();
        }
    }
}
