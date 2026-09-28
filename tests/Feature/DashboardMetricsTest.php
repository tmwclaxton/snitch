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
use App\Services\Dashboard\InsightRules;
use Carbon\CarbonImmutable;
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
                ->has('kpis.status')
                ->has('rail.cells', 9)
                ->missing('insights')
                ->missing('top_posts')
                ->loadDeferredProps('panel', fn (Assert $panel) => $panel
                    ->has('insights.status')
                    ->has('leaderboard.status')
                    ->has('winners.status')
                    ->has('activity.heatmap')
                    ->has('recent_posts')
                    ->has('caption_intel.hashtags')
                    ->where('leaderboard.data.rows.0.is_own_account', true)
                    ->missing('activity.by_platform')
                )
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

    public function test_hidden_likes_are_excluded_from_er_and_not_treated_as_zero(): void
    {
        $user = User::factory()->onTrial()->create();
        BrandProfile::factory()->for($user)->create();

        $hiddenAccount = TrackedAccount::factory()->for($user)->create([
            'platform' => Platform::Instagram,
            'handle' => 'goodgym',
            'followers' => 29000,
        ]);
        $visibleAccount = TrackedAccount::factory()->for($user)->create([
            'platform' => Platform::Instagram,
            'handle' => 'great.friendship',
            'followers' => 5000,
        ]);

        for ($i = 0; $i < 8; $i++) {
            Post::factory()->forAccount($hiddenAccount)->create([
                'type' => PostType::Reel,
                'posted_at' => now()->subDays($i + 1),
                'metrics' => [
                    'likes' => null,
                    'like_count_hidden' => true,
                    'comments' => 3,
                    'views' => 400,
                ],
            ]);
            Post::factory()->forAccount($visibleAccount)->create([
                'type' => PostType::Carousel,
                'posted_at' => now()->subDays($i + 1),
                'metrics' => ['likes' => 40, 'comments' => 4],
            ]);
        }

        $payload = app(DashboardMetrics::class)->forUser($user, [], 30);

        $this->assertContains('goodgym', $payload['selected']);
        $this->assertContains('great.friendship', $payload['selected']);

        $hiddenRow = collect($payload['leaderboard']['data']['rows'])->firstWhere('handle', 'goodgym');
        $visibleRow = collect($payload['leaderboard']['data']['rows'])->firstWhere('handle', 'great.friendship');

        $this->assertFalse($hiddenRow['no_posts_in_period']);
        $this->assertSame(8, $hiddenRow['posts_n']);
        $this->assertNull($hiddenRow['er']);
        $this->assertSame('Likes hidden on Instagram', $hiddenRow['er_reason']);
        $this->assertSame('Likes hidden on Instagram', $hiddenRow['row_note']);
        $this->assertNull($hiddenRow['top_format']);
        $this->assertNotNull($visibleRow['er']);
        $this->assertGreaterThan(0, $visibleRow['er']);

        $winnerHandles = collect($payload['winners']['data']['winners'] ?? [])->pluck('handle');
        $this->assertNotContains('goodgym', $winnerHandles->all());
    }

    public function test_growth_efficiency_format_and_heatmap_cards_return_shapes(): void
    {
        [$user] = $this->seedRichFixture();

        $payload = app(DashboardMetrics::class)->forUser($user, [], 30);

        $this->assertContains($payload['growth_series']['status'], ['ok', 'insufficient']);
        $this->assertIsArray($payload['growth_series']['data']['series'] ?? null);

        $this->assertSame('ok', $payload['efficiency']['status']);
        $this->assertGreaterThanOrEqual(2, count($payload['efficiency']['data']['points']));

        $this->assertSame('ok', $payload['format_mix']['status']);
        $this->assertNotEmpty($payload['format_mix']['data']['rows']);

        $this->assertContains($payload['format_lift']['status'], ['ok', 'insufficient']);
        $this->assertContains($payload['heatmap']['status'], ['ok', 'insufficient']);
        $this->assertCount(7, $payload['heatmap']['data']['days']);
        $this->assertCount(6, $payload['heatmap']['data']['blocks']);
    }

    public function test_insight_links_to_resolve_to_live_dashboard_anchors(): void
    {
        [$user] = $this->seedRichFixture();

        $payload = app(DashboardMetrics::class)->forUser($user, [], 30);
        $items = $payload['insights']['data']['items'] ?? [];
        $actions = $payload['actions']['data']['items'] ?? [];
        $rendered = $this->dashboardDomAnchors();

        $this->assertNotEmpty($items, 'Rich fixture should produce insights so see-why targets can be checked');

        foreach ($items as $item) {
            $this->assertDashboardLinkTargetExists((string) $item['links_to'], $rendered, 'insight');
        }

        foreach ($actions as $item) {
            $this->assertDashboardLinkTargetExists((string) $item['links_to'], $rendered, 'action');
        }

        foreach (InsightRules::LIVE_ANCHORS as $anchor) {
            $this->assertContains(
                $anchor,
                $rendered,
                "LIVE_ANCHORS entry '{$anchor}' is missing from Dashboard.vue id=/anchor= attributes",
            );
        }

        $rules = app(InsightRules::class);
        $this->assertSame('rail', $rules->resolveAnchor('kpis'));
        $this->assertSame('captions', $rules->resolveAnchor('captions'));
        $this->assertSame('themes', $rules->resolveAnchor('themes'));
        $this->assertSame('heatmap', $rules->resolveAnchor('heatmap'));
        $this->assertSame('/winners', $rules->resolveAnchor('/winners'));
    }

    /**
     * @return list<string>
     */
    private function dashboardDomAnchors(): array
    {
        $dashboard = file_get_contents(resource_path('js/pages/Dashboard.vue'));
        $this->assertNotFalse($dashboard);

        preg_match_all('/\bid="([^"]+)"/', $dashboard, $ids);
        preg_match_all('/\banchor="([^"]+)"/', $dashboard, $anchors);

        return array_values(array_unique(array_merge($ids[1] ?? [], $anchors[1] ?? [])));
    }

    /**
     * @param  list<string>  $rendered
     */
    private function assertDashboardLinkTargetExists(string $target, array $rendered, string $kind): void
    {
        if (str_starts_with($target, '/')) {
            $this->assertMatchesRegularExpression(
                '#^/(winners|tracking(?:/\d+)?|feed(?:/\d+)?)(?:\?.*)?$#',
                $target,
                ucfirst($kind)." path links_to '{$target}' is not an allowed in-app route",
            );

            return;
        }

        $this->assertContains(
            $target,
            InsightRules::LIVE_ANCHORS,
            ucfirst($kind)." links_to '{$target}' is not a live dashboard anchor",
        );
        $this->assertContains(
            $target,
            $rendered,
            ucfirst($kind)." links_to '{$target}' has no matching id=/anchor= on Dashboard.vue",
        );
    }

    public function test_inc3_cards_return_shapes_and_hidden_toggle_changes_cache_key(): void
    {
        [$user] = $this->seedRichFixture();

        $payload = app(DashboardMetrics::class)->forUser($user, [], 30, false);

        $this->assertContains($payload['captions']['status'], ['ok', 'insufficient']);
        $this->assertContains($payload['themes']['status'], ['ok', 'insufficient']);
        $this->assertContains($payload['weekly']['status'], ['ok', 'insufficient']);
        $this->assertContains($payload['attention']['status'], ['ok', 'insufficient']);
        $this->assertContains($payload['actions']['status'], ['ok', 'insufficient', 'empty']);
        $this->assertSame('ok', $payload['data_notes']['status']);
        $this->assertFalse($payload['show_hidden_likes']);

        $withHidden = app(DashboardMetrics::class)->forUser($user, [], 30, true);
        $this->assertTrue($withHidden['show_hidden_likes']);
    }

    public function test_heatmap_cells_are_europe_london_blocks(): void
    {
        $math = app(DashboardMath::class);
        $bucket = $math->londonBucket(CarbonImmutable::parse('2025-03-30 01:30:00', 'UTC'));

        $this->assertSame(6, $bucket['dow']);
        $this->assertSame(0, $bucket['block']);
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

    public function test_backup_port_payload_scopes_activity_and_omits_platform_split(): void
    {
        [$user] = $this->seedRichFixture();

        $payload = app(DashboardMetrics::class)->forUser($user, ['great.friendship'], 30);

        $this->assertCount(9, $payload['rail']['cells']);
        $this->assertTrue($payload['rail']['ready']);
        $this->assertArrayHasKey('heatmap', $payload['activity']);
        $this->assertArrayHasKey('weekly', $payload['activity']);
        $this->assertArrayHasKey('by_time_of_day', $payload['activity']);
        $this->assertArrayNotHasKey('by_platform', $payload['activity']);
        $this->assertIsArray($payload['follower_series']);
        $this->assertArrayHasKey('week_delta', $payload['growth_delta']);
        $this->assertIsArray($payload['recent_posts']);
        $this->assertLessThanOrEqual(24, count($payload['recent_posts']));
        $this->assertArrayHasKey('hashtags', $payload['caption_intel']);
        $this->assertArrayHasKey('keywords', $payload['caption_intel']);
        $this->assertArrayHasKey('ctas', $payload['caption_intel']);
        $this->assertArrayHasKey('format_mix', $payload['caption_intel']);

        foreach ($payload['recent_posts'] as $post) {
            $this->assertArrayNotHasKey('embed', $post);
            $this->assertArrayHasKey('cover_url', $post);
        }

        $erCell = collect($payload['rail']['cells'])->firstWhere('key', 'er');
        $this->assertNotNull($erCell);
        $this->assertStringContainsString('peer', strtolower((string) $erCell['hint']));
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
