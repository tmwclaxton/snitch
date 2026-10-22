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
        $this->assertStringContainsString('max-w-[52.5rem]', $page);
        $this->assertStringContainsString("What you've been doing", $page);
        $this->assertStringContainsString('Our suggestions', $page);
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
}
