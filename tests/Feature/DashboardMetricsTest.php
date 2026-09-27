<?php

namespace Tests\Feature;

use App\Enums\Platform;
use App\Enums\PostType;
use App\Models\BrandProfile;
use App\Models\FollowerSnapshot;
use App\Models\Post;
use App\Models\TrackedAccount;
use App\Models\User;
use App\Services\Dashboard\DashboardMath;
use App\Services\Dashboard\DashboardMetrics;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DashboardMetricsTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_returns_card_result_shape(): void
    {
        [$user] = $this->seedRichFixture();

        $this->actingAs($user)
            ->get(route('dashboard', ['period' => 30]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard')
                ->where('own_account.handle', 'letsgosocialuk')
                ->has('rivals', 2)
                ->has('insights.status')
                ->has('kpis.status')
                ->has('leaderboard.status')
                ->has('winners.status')
                ->where('leaderboard.data.rows.0.is_own_account', true)
                ->missing('top_posts')
            );
    }

    public function test_non_instagram_trackers_do_not_count_as_rivals_and_show_onboarding(): void
    {
        $user = User::factory()->onTrial()->create();
        BrandProfile::factory()->for($user)->create();

        TrackedAccount::factory()->for($user)->create([
            'platform' => Platform::Youtube,
            'handle' => 'brandwatch',
        ]);
        TrackedAccount::factory()->for($user)->create([
            'platform' => Platform::TikTok,
            'handle' => 'claragig',
        ]);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('rivals', 0)
                ->where('controls.has_non_instagram_trackers', true)
                ->where('onboarding.status', 'ok')
                ->where('onboarding.data.hide', false)
                ->where('onboarding.data.steps.1.label', 'Add Instagram competitors on Tracking')
            );
    }

    public function test_tracked_instagram_rivals_mark_onboarding_rivals_step_done(): void
    {
        $user = User::factory()->onTrial()->create();
        BrandProfile::factory()->for($user)->create();
        TrackedAccount::factory()->for($user)->create([
            'platform' => Platform::Instagram,
            'handle' => 'rivalbakery',
            'avatar' => null,
        ]);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('rivals', 1)
                ->where('rivals.0.handle', 'rivalbakery')
                ->where('onboarding.status', 'ok')
                ->where('onboarding.data.steps.1.done', true)
                ->where('onboarding.data.steps.1.label', 'Waiting for denser data (1 rival tracked - need 5+ posts in period)')
            );
    }

    public function test_insufficient_sample_never_returns_zero_percent_er(): void
    {
        $user = User::factory()->onTrial()->create();
        BrandProfile::factory()->for($user)->create();

        $own = TrackedAccount::factory()->for($user)->create([
            'platform' => Platform::Instagram,
            'handle' => 'tinyown',
            'followers' => 90,
            'is_own_account' => true,
        ]);
        $rival = TrackedAccount::factory()->for($user)->create([
            'platform' => Platform::Instagram,
            'handle' => 'tinyrival',
            'followers' => 500,
        ]);

        foreach ([$own, $rival] as $account) {
            for ($i = 0; $i < 3; $i++) {
                Post::factory()->forAccount($account)->create([
                    'posted_at' => now()->subDays($i + 1),
                    'metrics' => ['likes' => 5, 'comments' => 1],
                ]);
            }
        }

        $payload = app(DashboardMetrics::class)->forUser($user, ['tinyrival'], 30);
        $erCard = collect($payload['kpis']['data']['cards'])->firstWhere('key', 'er');

        $this->assertSame('insufficient', $erCard['status']);
        $this->assertNull($erCard['you']);
        $this->assertStringContainsString('n=3', (string) $erCard['reason']);

        $ownRow = collect($payload['leaderboard']['data']['rows'])->firstWhere('is_own_account', true);
        $this->assertNull($ownRow['er']);
        $this->assertStringContainsString('n=3', (string) $ownRow['er_reason']);
    }

    public function test_unknown_followers_show_as_null_not_zero(): void
    {
        $user = User::factory()->onTrial()->create();
        BrandProfile::factory()->for($user)->create();

        $rival = TrackedAccount::factory()->for($user)->create([
            'platform' => Platform::Instagram,
            'handle' => 'nofollowers',
            'followers' => null,
        ]);

        for ($i = 0; $i < 6; $i++) {
            Post::factory()->forAccount($rival)->create([
                'posted_at' => now()->subDays($i + 1),
                'metrics' => ['likes' => 8, 'comments' => 1],
            ]);
        }

        $payload = app(DashboardMetrics::class)->forUser($user, ['nofollowers'], 30);
        $row = $payload['leaderboard']['data']['rows'][0];

        $this->assertNull($row['followers']);
        $this->assertNull($row['er']);
    }

    public function test_winners_ranked_by_performance_index_not_raw_likes(): void
    {
        $user = User::factory()->onTrial()->create();
        BrandProfile::factory()->for($user)->create();

        $small = TrackedAccount::factory()->for($user)->create([
            'platform' => Platform::Instagram,
            'handle' => 'smallclub',
            'followers' => 200,
        ]);
        $big = TrackedAccount::factory()->for($user)->create([
            'platform' => Platform::Instagram,
            'handle' => 'bigclub',
            'followers' => 20000,
        ]);

        for ($i = 0; $i < 15; $i++) {
            Post::factory()->forAccount($small)->create([
                'type' => PostType::Carousel,
                'posted_at' => now()->subDays(60 - $i),
                'metrics' => ['likes' => 10, 'comments' => 0],
            ]);
            Post::factory()->forAccount($big)->create([
                'type' => PostType::Image,
                'posted_at' => now()->subDays(60 - $i),
                'metrics' => ['likes' => 400, 'comments' => 0],
            ]);
        }

        $outlier = Post::factory()->forAccount($small)->create([
            'type' => PostType::Carousel,
            'caption' => 'Tag a friend for Saturday games?',
            'posted_at' => now()->subDays(2),
            'metrics' => ['likes' => 50, 'comments' => 5],
            'cover_url' => 'https://example.com/small.jpg',
        ]);

        Post::factory()->forAccount($big)->create([
            'type' => PostType::Image,
            'caption' => 'Huge account ordinary post',
            'posted_at' => now()->subDays(2),
            'metrics' => ['likes' => 500, 'comments' => 10],
            'cover_url' => 'https://example.com/big.jpg',
        ]);

        $payload = app(DashboardMetrics::class)->forUser($user, ['smallclub', 'bigclub'], 30);
        $this->assertSame('ok', $payload['winners']['status']);
        $top = $payload['winners']['data']['winners'][0];

        $this->assertSame($outlier->id, $top['id']);
        $this->assertSame('smallclub', $top['handle']);
        $this->assertGreaterThanOrEqual(DashboardMath::WINNER_THRESHOLD, $top['pi']);
    }

    public function test_default_selection_prefers_rivals_with_posts_in_period(): void
    {
        $user = User::factory()->onTrial()->create();
        BrandProfile::factory()->for($user)->create();

        $empty = TrackedAccount::factory()->for($user)->create([
            'platform' => Platform::Instagram,
            'handle' => 'fuss.london',
            'followers' => 1800,
        ]);
        $busy = TrackedAccount::factory()->for($user)->create([
            'platform' => Platform::Instagram,
            'handle' => 'great.friendship',
            'followers' => 5000,
        ]);
        $alsoBusy = TrackedAccount::factory()->for($user)->create([
            'platform' => Platform::Instagram,
            'handle' => 'goodgym',
            'followers' => 29000,
        ]);

        foreach ([$busy, $alsoBusy] as $account) {
            for ($i = 0; $i < 6; $i++) {
                Post::factory()->forAccount($account)->create([
                    'posted_at' => now()->subDays($i + 1),
                    'metrics' => ['likes' => 12, 'comments' => 1],
                ]);
            }
        }

        $payload = app(DashboardMetrics::class)->forUser($user, [], 30);

        $this->assertContains('great.friendship', $payload['selected']);
        $this->assertContains('goodgym', $payload['selected']);
        $this->assertNotContains('fuss.london', $payload['selected']);

        $emptyChip = collect($payload['rivals'])->firstWhere('handle', 'fuss.london');
        $this->assertTrue($emptyChip['no_posts_in_period']);

        unset($empty);
    }

    public function test_growth_uses_real_snapshots_only(): void
    {
        $user = User::factory()->onTrial()->create();
        BrandProfile::factory()->for($user)->create();

        $rival = TrackedAccount::factory()->for($user)->create([
            'platform' => Platform::Instagram,
            'handle' => 'growing',
            'followers' => 1100,
        ]);

        FollowerSnapshot::factory()->create([
            'social_account_id' => $rival->social_account_id,
            'followers' => 1000,
            'captured_on' => now()->subDays(30)->toDateString(),
        ]);
        FollowerSnapshot::factory()->create([
            'social_account_id' => $rival->social_account_id,
            'followers' => 1100,
            'captured_on' => now()->toDateString(),
        ]);

        for ($i = 0; $i < 6; $i++) {
            Post::factory()->forAccount($rival)->create([
                'posted_at' => now()->subDays($i + 1),
                'metrics' => ['likes' => 20, 'comments' => 2],
            ]);
        }

        $payload = app(DashboardMetrics::class)->forUser($user, ['growing'], 30);
        $row = $payload['leaderboard']['data']['rows'][0];

        $this->assertEqualsWithDelta(10.0, (float) $row['growth_pct'], 0.2);
    }

    /**
     * @return array{0: User, 1: TrackedAccount, 2: list<TrackedAccount>}
     */
    private function seedRichFixture(): array
    {
        $user = User::factory()->onTrial()->create();
        BrandProfile::factory()->for($user)->create([
            'name' => "Let's Go Social",
        ]);

        $own = TrackedAccount::factory()->for($user)->create([
            'platform' => Platform::Instagram,
            'handle' => 'letsgosocialuk',
            'followers' => 93,
            'is_own_account' => true,
            'display_name' => "Let's Go Social",
            'avatar' => null,
        ]);

        $rivals = [];

        foreach ([
            ['great.friendship', 5000],
            ['fuss.london', 1800],
        ] as [$handle, $followers]) {
            $account = TrackedAccount::factory()->for($user)->create([
                'platform' => Platform::Instagram,
                'handle' => $handle,
                'followers' => $followers,
                'avatar' => 'https://example.com/'.$handle.'.jpg',
            ]);

            FollowerSnapshot::factory()->create([
                'social_account_id' => $account->social_account_id,
                'followers' => (int) ($followers * 0.95),
                'captured_on' => now()->subDays(28)->toDateString(),
            ]);
            FollowerSnapshot::factory()->create([
                'social_account_id' => $account->social_account_id,
                'followers' => $followers,
                'captured_on' => now()->toDateString(),
            ]);

            for ($i = 0; $i < 18; $i++) {
                Post::factory()->forAccount($account)->create([
                    'type' => $i % 3 === 0 ? PostType::Reel : ($i % 3 === 1 ? PostType::Carousel : PostType::Image),
                    'caption' => $i === 0 ? 'Who is coming this Saturday?' : "Meetup update {$i}",
                    'posted_at' => now()->subDays($i + 1)->setHour(19),
                    'metrics' => [
                        'likes' => 20 + ($i * 3),
                        'comments' => 2 + ($i % 4),
                        'views' => $i % 3 === 0 ? 800 + ($i * 40) : 0,
                    ],
                ]);
            }

            // Standout winner
            Post::factory()->forAccount($account)->create([
                'type' => PostType::Carousel,
                'caption' => 'Event recap - tag a friend who missed it',
                'posted_at' => now()->subDays(2)->setHour(20),
                'metrics' => ['likes' => 220, 'comments' => 40, 'views' => 0],
            ]);

            $rivals[] = $account;
        }

        for ($i = 0; $i < 4; $i++) {
            Post::factory()->forAccount($own)->create([
                'type' => PostType::Carousel,
                'caption' => "LGS update {$i}",
                'posted_at' => now()->subDays(($i + 1) * 5),
                'metrics' => ['likes' => 10 + $i, 'comments' => 1],
            ]);
        }

        return [$user, $own, $rivals];
    }
}
