<?php

namespace Tests\Feature;

use App\Enums\Platform;
use App\Enums\PostType;
use App\Models\BrandProfile;
use App\Models\Post;
use App\Models\TrackedAccount;
use App\Models\User;
use App\Services\Dashboard\DashboardMetrics;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardLeaderboardPresentationTest extends TestCase
{
    use RefreshDatabase;

    public function test_empty_rivals_appear_as_no_posts_yet_rows(): void
    {
        $user = User::factory()->create();
        BrandProfile::factory()->for($user)->create();

        $own = TrackedAccount::factory()->for($user)->forPlatform(Platform::Instagram)->create([
            'handle' => 'youbrand',
            'is_own_account' => true,
            'followers' => 1000,
        ]);

        $active = TrackedAccount::factory()->for($user)->forPlatform(Platform::Instagram)->create([
            'handle' => 'activeclub',
            'is_own_account' => false,
            'followers' => 2000,
        ]);

        $emptyA = TrackedAccount::factory()->for($user)->forPlatform(Platform::Instagram)->create([
            'handle' => 'fuss.london',
            'is_own_account' => false,
            'followers' => 500,
        ]);

        $emptyB = TrackedAccount::factory()->for($user)->forPlatform(Platform::Instagram)->create([
            'handle' => 'london.theofflineclub',
            'is_own_account' => false,
            'followers' => 800,
        ]);

        for ($i = 0; $i < 6; $i++) {
            Post::factory()->forAccount($own)->create([
                'type' => PostType::Carousel,
                'posted_at' => now()->subDays($i + 1),
                'metrics' => ['likes' => 40, 'comments' => 4],
            ]);
            Post::factory()->forAccount($active)->create([
                'type' => PostType::Reel,
                'posted_at' => now()->subDays($i + 1),
                'metrics' => ['likes' => 30, 'comments' => 3],
            ]);
        }

        $payload = app(DashboardMetrics::class)->forUser($user, [], 30);

        $this->assertContains('activeclub', $payload['selected']);
        $this->assertContains('fuss.london', $payload['selected']);
        $this->assertContains('london.theofflineclub', $payload['selected']);
        $this->assertSame(6, $payload['max_compare']);

        $rows = collect($payload['leaderboard']['data']['rows']);
        $this->assertNotNull($rows->firstWhere('handle', 'fuss.london'));
        $this->assertTrue($rows->firstWhere('handle', 'fuss.london')['no_posts_in_period']);
        $this->assertSame('No posts yet', $rows->firstWhere('handle', 'fuss.london')['row_note']);
        $this->assertTrue($rows->firstWhere('handle', 'london.theofflineclub')['no_posts_in_period']);
        $this->assertFalse($rows->firstWhere('handle', 'activeclub')['no_posts_in_period']);
    }

    public function test_kpi_uses_engagement_rate_label_not_er_jargon(): void
    {
        $user = User::factory()->create();
        BrandProfile::factory()->for($user)->create();
        $own = TrackedAccount::factory()->for($user)->forPlatform(Platform::Instagram)->create([
            'handle' => 'youbrand',
            'is_own_account' => true,
            'followers' => 1000,
        ]);

        for ($i = 0; $i < 6; $i++) {
            Post::factory()->forAccount($own)->create([
                'posted_at' => now()->subDays($i + 1),
                'metrics' => ['likes' => 20, 'comments' => 2],
            ]);
        }

        $payload = app(DashboardMetrics::class)->forUser($user, [], 30);
        $cards = collect($payload['kpis']['data']['cards']);
        $er = $cards->firstWhere('key', 'er');

        $this->assertSame('Engagement rate', $er['label']);
        $this->assertSame('likes + comments per post, as a % of followers', $er['why']);
        $this->assertStringNotContainsString('ER (per follower)', (string) $er['label']);
        $this->assertNull($cards->firstWhere('key', 'followers_growth'));
        $this->assertNull($cards->firstWhere('key', 'reel_reach'));
        $this->assertGreaterThanOrEqual(3, $cards->count());
    }

    public function test_own_engagement_shows_when_few_measurable_posts_exist(): void
    {
        $user = User::factory()->create();
        BrandProfile::factory()->for($user)->create();

        $own = TrackedAccount::factory()->for($user)->forPlatform(Platform::Instagram)->create([
            'handle' => 'letsgosocialuk',
            'is_own_account' => true,
            'followers' => 1000,
        ]);

        Post::factory()->forAccount($own)->create([
            'posted_at' => now()->subDays(2),
            'metrics' => ['likes' => 143, 'comments' => 0],
        ]);

        $payload = app(DashboardMetrics::class)->forUser($user, [], 30);
        $cards = collect($payload['kpis']['data']['cards']);
        $er = $cards->firstWhere('key', 'er');
        $winnerRate = $cards->firstWhere('key', 'winner_rate');
        $ownRow = collect($payload['leaderboard']['data']['rows'])->firstWhere('is_own_account', true);

        $this->assertSame(14.3, $er['you']);
        $this->assertNull($er['reason']);
        $this->assertNotNull($winnerRate['you']);
        $this->assertSame(14.3, $ownRow['er']);
        $this->assertNull($ownRow['er_reason']);
    }

    public function test_posts_per_week_gap_uses_the_same_rounded_counts_as_the_headline(): void
    {
        $user = User::factory()->create();
        BrandProfile::factory()->for($user)->create();

        $own = TrackedAccount::factory()->for($user)->forPlatform(Platform::Instagram)->create([
            'handle' => 'letsgosocialuk',
            'is_own_account' => true,
            'followers' => 1000,
        ]);

        $rival = TrackedAccount::factory()->for($user)->forPlatform(Platform::Instagram)->create([
            'handle' => 'onehousesocialclub',
            'is_own_account' => false,
            'followers' => 2000,
        ]);

        for ($i = 0; $i < 4; $i++) {
            Post::factory()->forAccount($own)->create([
                'posted_at' => now()->subDays($i + 1),
                'metrics' => ['likes' => 10, 'comments' => 1, 'like_count_hidden' => true],
            ]);
        }

        for ($i = 0; $i < 12; $i++) {
            Post::factory()->forAccount($rival)->create([
                'posted_at' => now()->subDays($i + 1),
                'metrics' => ['likes' => 20, 'comments' => 2],
            ]);
        }

        $payload = app(DashboardMetrics::class)->forUser($user, [], 30);
        $ppw = collect($payload['kpis']['data']['cards'])->firstWhere('key', 'posts_per_week');

        $this->assertSame(1.0, $ppw['you']);
        $this->assertSame(3.0, $ppw['peer_median']);
        $this->assertTrue($ppw['gap']['lower']);
        $this->assertSame(3.0, $ppw['gap']['value']);
        $this->assertSame(
            'You post 1 a week; rivals post 3. Room to publish more.',
            $payload['executive']['performance']['headline'],
        );
    }

    public function test_own_engagement_explains_when_likes_are_hidden(): void
    {
        $user = User::factory()->create();
        BrandProfile::factory()->for($user)->create();

        $own = TrackedAccount::factory()->for($user)->forPlatform(Platform::Instagram)->create([
            'handle' => 'letsgosocialuk',
            'is_own_account' => true,
            'followers' => 1000,
        ]);

        Post::factory()->forAccount($own)->create([
            'posted_at' => now()->subDays(2),
            'metrics' => ['likes' => 10, 'comments' => 1, 'like_count_hidden' => true],
        ]);

        $payload = app(DashboardMetrics::class)->forUser($user, [], 30);
        $er = collect($payload['kpis']['data']['cards'])->firstWhere('key', 'er');
        $ownRow = collect($payload['leaderboard']['data']['rows'])->firstWhere('is_own_account', true);

        $this->assertNull($er['you']);
        $this->assertSame('Likes hidden on Instagram', $er['reason']);
        $this->assertNull($ownRow['er']);
        $this->assertSame('Likes hidden on Instagram', $ownRow['er_reason']);
    }
}
