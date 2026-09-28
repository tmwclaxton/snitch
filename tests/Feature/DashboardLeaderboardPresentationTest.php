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
        $er = collect($payload['kpis']['data']['cards'])->firstWhere('key', 'er');
        $followers = collect($payload['kpis']['data']['cards'])->firstWhere('key', 'followers_growth');

        $this->assertSame('Engagement rate', $er['label']);
        $this->assertSame('likes + comments per post, as a % of followers', $er['why']);
        $this->assertStringNotContainsString('ER (per follower)', (string) $er['label']);
        $this->assertSame('growth from next week', $followers['reason']);
    }
}
