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
        $this->assertStringContainsString('max-w-[1100px]', $page);
        $this->assertStringContainsString('max-w-[70ch]', $page);
        $this->assertStringContainsString('What to do', $page);
        $this->assertStringContainsString('What happened this week', $page);
        $this->assertStringContainsString('Key figures', $page);
        $this->assertStringContainsString('narrativeText', $page);
        $this->assertStringContainsString('awaitingNarrative', $page);
        $this->assertStringContainsString('snitch-memo-headline', $page);
        $this->assertStringContainsString('break-words', $page);
        $this->assertStringNotContainsString('v-if="brief.headline_detail"', $page);
        $this->assertStringNotContainsString('PanelBigNumber', $page);
        $this->assertStringNotContainsString('text-snitch-spot', $page);
        $this->assertStringNotContainsString('rounded border border-snitch-ink/12 bg-snitch-lift', $page);
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
                'narrative' => 'Own club is steady this week with one Reel already shipped, while rivals posted more Reels across the stored window. The gap is cadence and format focus rather than audience size: followers sit near 1500 with a modest lift. Start by posting another Reel in a proven evening block so you stay visible where attention already sits. Then leave one grounded comment on a rival hit above 1.2 times their usual instead of copying weak posts. Keep the brief short and ship something concrete before the week drifts quiet again.',
                'automation_overrides' => [
                    'updated_at' => now()->toIso8601String(),
                    'run_uuid' => 'test-run',
                    'sections' => [],
                ],
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
                ->has('brief.narrative')
                ->has('brief.key_figures')
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
                'narrative' => implode(' ', array_fill(0, 90, 'word')),
                'automation_overrides' => [
                    'updated_at' => now()->toIso8601String(),
                    'run_uuid' => 'shape-run',
                    'sections' => [],
                ],
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
        // Agent memos use interpretive own/competitor prose, not the per-handle stats inventory.
        $this->assertCount(1, $memo['own_activity']);
        $this->assertStringContainsString('quiet for 21 days', $memo['own_activity'][0]['text']);
        $this->assertCount(1, $memo['competitor_activity']);
        $this->assertStringContainsString('Competitors published 13 posts', $memo['competitor_activity'][0]['text']);
        $this->assertFalse(collect($memo['suggestions'])->contains(
            fn (array $row): bool => str_contains(mb_strtolower($row['title']), 'post this next'),
        ));
        $this->assertSame(1, $memo['suggestions'][0]['number']);
        $this->assertStringContainsString('Carousel', $memo['suggestions'][0]['title']);
        $this->assertStringContainsString('Data last updated 22 Oct, 07:15', (string) $memo['freshness']['line']);
        $this->assertStringContainsString('@badhandle', (string) $memo['freshness']['line']);
    }

    public function test_what_happened_keeps_one_section_and_diverse_thumbs(): void
    {
        $user = User::factory()->create();
        BrandProfile::factory()->for($user)->create();
        TrackedAccount::factory()->for($user)->create([
            'platform' => Platform::Instagram,
            'handle' => 'ownclub',
            'is_own_account' => true,
        ]);

        $narrative = implode(' ', [
            'You have been quiet for three weeks, so the first job is a post, not a rebrand.',
            'One House said the awkward part out loud on a Reel about leaving a crowded room without a real conversation.',
            'That Reel reached 3.2 times their usual.',
            'Film a weekday evening Reel around who someone will actually talk to.',
            implode(' ', array_fill(0, 40, 'word')),
        ]);

        DailyBrief::query()->create([
            'user_id' => $user->id,
            'brief_date' => now('Europe/London')->toDateString(),
            'status' => 'ready',
            'headline' => 'Quiet week while rivals keep posting.',
            'payload' => [
                'headline' => 'Quiet week while rivals keep posting.',
                'narrative' => $narrative,
                'automation_overrides' => [
                    'updated_at' => now()->toIso8601String(),
                    'run_uuid' => 'thumbs-run',
                    'sections' => [
                        [
                            'title' => 'What happened this week',
                            'body' => 'Sober Social added 56 followers. GoodGym hit 12.6 times their usual on a Carousel. Great Friendship stayed near usual.',
                        ],
                        [
                            'title' => 'Rival patterns this week',
                            'body' => 'Duplicate section that should not render a second thumb row.',
                        ],
                    ],
                ],
                'own_summary' => 'You are at 104 followers after 21 quiet days.',
                'competitor_summary' => '',
                'own_last_7_days' => [
                    ['post_id' => 11, 'handle' => 'ownclub', 'format' => 'Carousel', 'times_usual' => 1.0, 'times_usual_label' => '1.0x'],
                ],
                'actions' => [
                    [
                        'title' => 'Post a Reel tonight.',
                        'why' => 'Break the quiet streak with a meet-new-people angle.',
                        'related_post_ids' => [24],
                        'inspiration' => [],
                    ],
                    [
                        'title' => 'Comment on a rival.',
                        'why' => 'Show up while the thread is still warm.',
                        'related_post_ids' => [],
                        'inspiration' => [],
                    ],
                    [
                        'title' => 'Test a Carousel.',
                        'why' => 'Borrow the mission layout that is already working.',
                        'related_post_ids' => [21],
                        'inspiration' => [],
                    ],
                ],
                'competitor_moves' => [
                    ['post_id' => 21, 'handle' => 'goodgym', 'format' => 'Carousel', 'times_usual' => 12.6, 'times_usual_label' => '12.6 times their usual'],
                    ['post_id' => 22, 'handle' => 'goodgym', 'format' => 'Reel', 'times_usual' => 11.0, 'times_usual_label' => '11.0 times their usual'],
                    ['post_id' => 23, 'handle' => 'sobersocial_', 'format' => 'Reel', 'times_usual' => 2.0, 'times_usual_label' => '2.0 times their usual'],
                    ['post_id' => 24, 'handle' => 'onehousesocialclub', 'format' => 'Reel', 'times_usual' => 3.2, 'times_usual_label' => '3.2 times their usual'],
                ],
                'data_freshness' => [],
            ],
            'facts' => [
                'own' => [
                    'handle' => 'ownclub',
                    'followers_now' => 104,
                    'posts_last_7d_count' => 0,
                    'days_since_last_post' => 21,
                ],
                'format_mix' => ['competitor_posts_7d' => 5],
                'competitors' => [],
                'top_competitor_hit_7d' => null,
            ],
            'model' => 'automation',
            'llm_attempts' => 1,
            'was_free' => true,
            'generated_at' => now(),
        ]);

        $memo = app(ExecutiveBriefPresenter::class)->forUser($user);

        $this->assertCount(1, $memo['competitor_activity']);
        $this->assertStringContainsString('Sober Social added 56', $memo['competitor_activity'][0]['text']);
        $this->assertStringNotContainsString('Duplicate section', $memo['competitor_activity'][0]['text']);
        $this->assertCount(0, $memo['own_activity'][0]['posts'] ?? []);
        $handles = collect($memo['competitor_activity'][0]['posts'] ?? [])
            ->map(fn (array $post): string => (string) ($post['handle'] ?? ''))
            ->filter()
            ->values()
            ->all();
        $this->assertLessThanOrEqual(2, count($handles));
        $this->assertSame($handles, array_values(array_unique($handles)), 'evidence thumbs should not repeat the same handle');
        $this->assertNotContains('onehousesocialclub', $handles, 'narrative-cited lead post must not appear under What happened');
        $this->assertNotContains('goodgym', $handles, 'action-cited evidence must not appear under What happened');
        $this->assertContains('sobersocial_', $handles);
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
                'narrative' => implode(' ', array_fill(0, 90, 'word')),
                'automation_overrides' => [
                    'updated_at' => now()->toIso8601String(),
                    'run_uuid' => 'dedupe-run',
                    'sections' => [],
                ],
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
