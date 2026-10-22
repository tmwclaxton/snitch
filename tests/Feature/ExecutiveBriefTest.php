<?php

namespace Tests\Feature;

use App\Enums\Platform;
use App\Models\BrandProfile;
use App\Models\DailyBrief;
use App\Models\Post;
use App\Models\TrackedAccount;
use App\Models\User;
use App\Services\Brief\ExecutiveBriefPresenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ExecutiveBriefTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_route_serves_executive_brief_and_old_path_redirects(): void
    {
        $user = User::factory()->create();
        BrandProfile::factory()->for($user)->create();

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('ExecutiveBrief')
                ->has('brief')
                ->where('brief.empty', true)
            );

        $this->assertSame('/executive-brief', parse_url(route('dashboard'), PHP_URL_PATH));

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertRedirect('/executive-brief');
    }

    public function test_sidebar_renames_landing_to_executive_brief(): void
    {
        $sidebar = file_get_contents(resource_path('js/components/AppSidebar.vue'));
        $page = file_get_contents(resource_path('js/pages/ExecutiveBrief.vue'));

        $this->assertNotFalse($sidebar);
        $this->assertNotFalse($page);
        $this->assertStringContainsString("title: 'Executive Brief'", $sidebar);
        $this->assertStringNotContainsString("title: 'Dashboard'", $sidebar);
        $this->assertStringContainsString('max-w-[1280px]', $page);
        $this->assertStringContainsString("What you've been doing", $page);
        $this->assertStringContainsString('Our suggestions', $page);
        $this->assertStringContainsString('Your 7-day cadence', $page);
        $this->assertStringContainsString('timesUsualPct', $page);
        $this->assertStringNotContainsString('PanelBigNumber', $page);
        $this->assertStringNotContainsString('text-snitch-spot', $page);
    }

    public function test_executive_brief_renders_ready_daily_brief_memo(): void
    {
        $user = User::factory()->create();
        BrandProfile::factory()->for($user)->create();
        $own = TrackedAccount::factory()->for($user)->create([
            'platform' => Platform::Instagram,
            'handle' => 'ownclub',
            'followers' => 1500,
            'is_own_account' => true,
        ]);
        Post::factory()->forAccount($own)->create([
            'posted_at' => now()->subDay(),
            'metrics' => ['likes' => 40, 'comments' => 3, 'views' => 900],
        ]);

        DailyBrief::query()->create([
            'user_id' => $user->id,
            'brief_date' => now('Europe/London')->toDateString(),
            'status' => 'ready',
            'headline' => 'Post a Reel today.',
            'payload' => [
                'headline' => 'Post a Reel today.',
                'summary' => 'Own club is steady. Rivals posted more Reels this week.',
                'own_summary' => 'Own club is steady.',
                'competitor_summary' => 'Rivals posted more Reels this week.',
                'actions' => [
                    [
                        'title' => 'Post a Reel at 20:00.',
                        'why' => 'Reels average 2.1 times your usual vs 0.8 for carousels over 30 days, n=12.',
                        'how' => 'Keep it under 30 seconds.',
                        'related_post_ids' => [],
                        'inspiration' => [],
                        'done_at' => null,
                    ],
                ],
                'own_last_7_days' => [],
                'competitor_moves' => [],
                'data_freshness' => [],
                'watch' => [],
            ],
            'facts' => [
                'own' => [
                    'handle' => 'ownclub',
                    'followers_now' => 1500,
                    'posts_last_7d_count' => 1,
                    'posts_last_7d_by_format' => ['Reel' => 1],
                    'posts_last_30d_by_format' => ['Reel' => 3],
                    'followers_change_7d' => ['label' => '+10 since 1 Oct', 'change' => 10],
                    'days_since_last_post' => 1,
                ],
                'competitors' => [],
            ],
            'model' => 'fallback',
            'llm_attempts' => 0,
            'was_free' => true,
            'generated_at' => now(),
        ]);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('ExecutiveBrief')
                ->where('brief.empty', false)
                ->where('brief.summary', 'Own club is steady. Rivals posted more Reels this week.')
                ->has('brief.own_activity')
                ->has('brief.suggestions', 1)
                ->where('brief.suggestions.0.why', 'Reels average 2.1 times your usual vs 0.8 for carousels over 30 days, n=12.')
            );
    }

    public function test_presenter_marks_empty_without_trackers(): void
    {
        $user = User::factory()->create();
        $brief = app(ExecutiveBriefPresenter::class)->forUser($user);

        $this->assertTrue($brief['empty']);
        $this->assertStringContainsString('competitors', strtolower((string) $brief['empty_reason']));
    }

    public function test_presenter_shapes_headline_freshness_and_zero_post_rivals(): void
    {
        $user = User::factory()->create();
        BrandProfile::factory()->for($user)->create();
        TrackedAccount::factory()->for($user)->create([
            'platform' => Platform::Instagram,
            'handle' => 'ownclub',
            'followers' => 104,
            'is_own_account' => true,
        ]);

        DailyBrief::query()->create([
            'user_id' => $user->id,
            'brief_date' => now('Europe/London')->toDateString(),
            'status' => 'ready',
            'headline' => '21 days quiet while rivals posted 13 times.',
            'payload' => [
                'headline' => '21 days quiet while rivals posted 13 times.',
                'summary' => 'Quiet stretch while rivals kept posting.',
                'own_summary' => '@ownclub has been quiet for 21 days while rivals published 13 posts this week.',
                'competitor_summary' => 'Competitors published 13 posts in the last 7 days.',
                'actions' => [
                    [
                        'title' => 'Use unused Post this next idea 1.',
                        'why' => 'This week\'s brief still has unused ideas.',
                        'how' => 'Ignore me.',
                        'related_post_ids' => [],
                        'inspiration' => [],
                    ],
                    [
                        'title' => 'Post a Carousel today at 20:00.',
                        'why' => '@goodgym Carousel got 12.6 times their usual. Sample at 20:00 is 12 posts.',
                        'how' => 'Ship one today.',
                        'related_post_ids' => [],
                        'inspiration' => [],
                    ],
                ],
                'own_last_7_days' => [],
                'competitor_moves' => [
                    [
                        'handle' => 'goodgym',
                        'posts_last_7d_count' => 4,
                        'posts_last_7d' => [],
                        'standout_winners' => [],
                        'quiet' => false,
                        'sync_empty' => false,
                        'sync_failed' => false,
                        'followers_change_7d' => ['label' => '+3 this week'],
                    ],
                    [
                        'handle' => 'quietclub',
                        'posts_last_7d_count' => 0,
                        'posts_last_7d' => [],
                        'standout_winners' => [],
                        'quiet' => true,
                        'sync_empty' => false,
                        'sync_failed' => false,
                        'days_since_last_post' => 40,
                    ],
                    [
                        'handle' => 'emptyclub',
                        'posts_last_7d_count' => 0,
                        'posts_last_7d' => [],
                        'standout_winners' => [],
                        'quiet' => false,
                        'sync_empty' => true,
                        'sync_failed' => false,
                    ],
                ],
                'data_freshness' => [
                    [
                        'handle' => 'ownclub',
                        'last_synced_at' => '2026-10-22T07:15:00+01:00',
                        'sync_status' => 'success',
                        'sync_failed' => false,
                        'sync_empty' => false,
                    ],
                    [
                        'handle' => 'badhandle',
                        'last_synced_at' => null,
                        'sync_status' => 'failed',
                        'sync_failed' => true,
                        'sync_empty' => false,
                    ],
                ],
                'watch' => [],
            ],
            'facts' => [
                'own' => [
                    'handle' => 'ownclub',
                    'followers_now' => 104,
                    'posts_last_7d_count' => 0,
                    'posts_last_7d_by_format' => [],
                    'posts_last_30d_by_format' => ['Reel' => 1],
                    'followers_change_7d' => ['label' => '+1 since 15 October', 'change' => 1],
                    'days_since_last_post' => 21,
                ],
                'format_mix' => ['competitor_posts_7d' => 13],
                'competitors' => [],
            ],
            'model' => 'fallback',
            'llm_attempts' => 0,
            'was_free' => true,
            'generated_at' => now(),
        ]);

        $memo = app(ExecutiveBriefPresenter::class)->forUser($user);

        $this->assertFalse($memo['empty']);
        $this->assertSame('21 days quiet while rivals posted 13 times.', $memo['headline']);
        $this->assertStringContainsString('quiet for 21 days', (string) $memo['summary']);
        $this->assertCount(1, $memo['own_activity']);
        $this->assertStringContainsString('0 posts in the last 7 days', $memo['own_activity'][0]['text']);
        $this->assertTrue(collect($memo['competitor_activity'])->contains(
            fn (array $row): bool => str_contains($row['text'], '@quietclub')
                && str_contains($row['text'], '@emptyclub')
                && str_contains($row['text'], 'posted 0 times'),
        ));
        $this->assertFalse(collect($memo['suggestions'])->contains(
            fn (array $row): bool => str_contains(mb_strtolower($row['title']), 'post this next'),
        ));
        $this->assertSame(1, $memo['suggestions'][0]['number']);
        $this->assertStringContainsString('Carousel', $memo['suggestions'][0]['title']);
        $this->assertStringContainsString('Data last updated 22 Oct, 07:15', (string) $memo['freshness']['line']);
        $this->assertStringContainsString('@badhandle', (string) $memo['freshness']['line']);
    }

    public function test_presenter_dedupes_repeated_competitor_volume_in_summary(): void
    {
        $user = User::factory()->create();
        BrandProfile::factory()->for($user)->create();
        TrackedAccount::factory()->for($user)->create([
            'platform' => Platform::Instagram,
            'handle' => 'aprilrosemarketing',
            'is_own_account' => true,
        ]);

        DailyBrief::query()->create([
            'user_id' => $user->id,
            'brief_date' => now('Europe/London')->toDateString(),
            'status' => 'ready',
            'headline' => 'Post a Reel today.',
            'payload' => [
                'headline' => 'Post a Reel today.',
                'own_summary' => '@aprilrosemarketing posted nothing in the last 7 days while competitors published 5 posts.',
                'competitor_summary' => 'Competitors published 5 posts in the last 7 days, including 0 posts in the last 24 hours. Strongest recent post: @yellowzest Reel from 18 Oct at 3.2 times their usual.',
                'actions' => [
                    [
                        'title' => 'Post a Reel today.',
                        'why' => '@yellowzest Reel got 3.2 times their usual.',
                        'related_post_ids' => [],
                        'inspiration' => [],
                    ],
                ],
                'own_last_7_days' => [],
                'competitor_moves' => [],
                'data_freshness' => [],
            ],
            'facts' => [
                'own' => [
                    'handle' => 'aprilrosemarketing',
                    'followers_now' => 200,
                    'posts_last_7d_count' => 0,
                    'days_since_last_post' => 10,
                ],
                'format_mix' => ['competitor_posts_7d' => 5],
            ],
            'model' => 'fallback',
            'llm_attempts' => 0,
            'was_free' => true,
            'generated_at' => now(),
        ]);

        $memo = app(ExecutiveBriefPresenter::class)->forUser($user);
        $summary = (string) $memo['summary'];

        $this->assertSame(1, substr_count(mb_strtolower($summary), 'competitors published 5 posts'));
        $this->assertStringContainsString('Strongest recent post: @yellowzest', $summary);
    }
}
