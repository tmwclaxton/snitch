<?php

namespace Tests\Feature\Brief;

use App\Enums\AnalysisStatus;
use App\Enums\TrackedAccountKind;
use App\Jobs\GenerateWeeklyBriefJob;
use App\Models\BrandProfile;
use App\Models\Post;
use App\Models\PostAnalysis;
use App\Models\TrackedAccount;
use App\Models\User;
use App\Models\WeeklyBrief;
use App\Services\Brief\WeeklyBriefGenerator;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class WeeklyBriefGenerationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'snitch.nanogpt.api_key' => 'test-key',
            'snitch.nanogpt.base_url' => 'https://nano-gpt.test/api/v1',
            'snitch.brief.model' => 'test-model',
            'snitch.brief.min_competitors' => 2,
            'snitch.brief.min_analysed_posts_30d' => 10,
            'snitch.brief.min_winner_candidates' => 3,
            'snitch.brief.debounce_seconds' => 1,
            'snitch.admin_emails' => ['admin@snitch.test'],
        ]);
    }

    public function test_automatic_brief_is_free_when_data_is_sufficient(): void
    {
        $this->fakeLlm();

        $user = User::factory()->create();
        BrandProfile::factory()->for($user)->create([
            'name' => 'GoodGym',
            'description' => 'Community fitness charity',
        ]);

        $this->seedEnoughCompetitorData($user);

        $brief = app(WeeklyBriefGenerator::class)->generate($user, force: false, billable: false);

        $this->assertTrue($brief->was_free);
        $this->assertSame(0.0, (float) $brief->credits_charged_pence);
        $this->assertCount(3, $brief->ideas);
        $this->assertSame('Open on the proof', $brief->ideas->first()->hook);

        $slots = $brief->best_times ?? [];
        foreach ($brief->ideas as $index => $idea) {
            if (! isset($slots[$index])) {
                continue;
            }

            $this->assertSame(
                (string) $slots[$index]['day'],
                (string) $idea->recommended_day,
                'Idea '.($index + 1).' should use timing slot '.($index + 1),
            );
            $this->assertSame(
                (int) $slots[$index]['hour'],
                (int) $idea->recommended_hour,
            );
        }

        foreach ($brief->ideas as $idea) {
            $this->assertNotEmpty($idea->inspired_by_post_ids, 'Every idea must cite a source winner');
        }
    }

    public function test_ideas_reassign_repeated_sources_and_sanitize_hook_cta(): void
    {
        $user = User::factory()->create();
        BrandProfile::factory()->for($user)->create(['name' => 'GoodGym']);
        $winnerIds = $this->seedEnoughCompetitorData($user);
        $shared = $winnerIds[0];

        Http::fake([
            'https://nano-gpt.test/api/v1/chat/completions' => Http::response([
                'choices' => [[
                    'message' => [
                        'content' => json_encode([
                            'ideas' => [
                                [
                                    'format' => 'Reel',
                                    'hook' => 'Cover slide: bold title with a megaphone icon, promising results',
                                    'caption_angle' => 'Show the proof',
                                    'cta' => 'Swipe up in bio',
                                    'hashtags' => ['#fitness', '#community', '#london'],
                                    'inspired_by_post_ids' => [$shared],
                                    'why' => 'Remix the winner.',
                                ],
                                [
                                    'format' => 'Carousel',
                                    'hook' => 'Three mistakes',
                                    'visual' => 'Grid of three traps',
                                    'caption_angle' => 'List the traps',
                                    'cta' => 'Save this',
                                    'hashtags' => ['#tips', '#growth', '#brand'],
                                    'inspired_by_post_ids' => [$shared],
                                    'why' => 'Same source twice is fine once.',
                                ],
                                [
                                    'format' => 'Image',
                                    'hook' => 'One bold claim',
                                    'caption_angle' => 'Single visual',
                                    'cta' => 'Link in bio',
                                    'hashtags' => ['#brand', '#content', '#social'],
                                    'inspired_by_post_ids' => [$shared],
                                    'why' => 'Third reuse must be reassigned.',
                                ],
                            ],
                        ]),
                    ],
                ]],
            ]),
        ]);

        $brief = app(WeeklyBriefGenerator::class)->generate($user, force: false, billable: false);

        $this->assertCount(3, $brief->ideas);

        $sourceCounts = [];
        foreach ($brief->ideas as $idea) {
            $this->assertNotEmpty($idea->inspired_by_post_ids);
            foreach ($idea->inspired_by_post_ids as $id) {
                $sourceCounts[(int) $id] = ($sourceCounts[(int) $id] ?? 0) + 1;
            }
        }

        foreach ($sourceCounts as $id => $count) {
            $this->assertLessThanOrEqual(2, $count, "Source {$id} used more than twice");
        }

        $first = $brief->ideas->firstWhere('position', 1);
        $this->assertNotNull($first);
        $this->assertNotSame('Cover slide: bold title with a megaphone icon, promising results', $first->hook);
        $this->assertTrue(str_word_count((string) $first->hook) <= 12);
        $this->assertNotNull($first->visual);
        $this->assertSame('Comment your take', $first->cta);

        $third = $brief->ideas->firstWhere('position', 3);
        $this->assertNotNull($third);
        $this->assertNotContains($shared, $third->inspired_by_post_ids);
    }

    public function test_admin_sees_can_regenerate_non_admin_does_not(): void
    {
        $admin = User::factory()->create(['email' => 'admin@snitch.test']);
        BrandProfile::factory()->for($admin)->create();
        WeeklyBrief::factory()->for($admin)->create([
            'status' => 'ready',
            'week_start' => app(WeeklyBriefGenerator::class)->currentWeekStart()->toDateString(),
        ]);

        $this->actingAs($admin)
            ->get(route('brief.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('brief/Index')
                ->where('canRegenerate', true)
            );

        $customer = User::factory()->create(['email' => 'customer@example.com']);
        BrandProfile::factory()->for($customer)->create();
        WeeklyBrief::factory()->for($customer)->create([
            'status' => 'ready',
            'week_start' => app(WeeklyBriefGenerator::class)->currentWeekStart()->toDateString(),
        ]);

        $this->actingAs($customer)
            ->get(route('brief.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('brief/Index')
                ->where('canRegenerate', false)
            );

        $source = file_get_contents(resource_path('js/pages/brief/Index.vue'));
        $this->assertIsString($source);
        $this->assertStringContainsString('v-if="canRegenerate && brief"', $source);
    }

    public function test_queue_if_ready_skips_when_insufficient_data(): void
    {
        Queue::fake([GenerateWeeklyBriefJob::class]);

        $user = User::factory()->create();
        BrandProfile::factory()->for($user)->create();
        TrackedAccount::factory()->for($user)->create([
            'is_own_account' => false,
            'kind' => TrackedAccountKind::Competitor,
        ]);

        $queued = app(WeeklyBriefGenerator::class)->queueIfReady($user);

        $this->assertFalse($queued);
        Queue::assertNothingPushed();
    }

    public function test_queue_if_ready_dispatches_when_threshold_met(): void
    {
        Queue::fake([GenerateWeeklyBriefJob::class]);

        $user = User::factory()->create();
        BrandProfile::factory()->for($user)->create();
        $this->seedEnoughCompetitorData($user);

        $queued = app(WeeklyBriefGenerator::class)->queueIfReady($user);

        $this->assertTrue($queued);
        Queue::assertPushed(GenerateWeeklyBriefJob::class, fn (GenerateWeeklyBriefJob $job) => $job->userId === $user->id
            && $job->force === false
            && $job->billable === false);
    }

    public function test_brief_page_empty_state_has_no_generate_cta(): void
    {
        $user = User::factory()->create();
        BrandProfile::factory()->for($user)->create();

        $this->actingAs($user)
            ->get(route('brief.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('brief/Index')
                ->where('brief', null)
                ->where('canRegenerate', false)
            );

        $source = file_get_contents(resource_path('js/pages/brief/Index.vue'));
        $this->assertIsString($source);
        $this->assertStringContainsString(
            'Your first brief appears automatically once Snitch has enough competitor posts analysed.',
            $source,
        );
        $this->assertStringNotContainsString('Generate free brief', $source);
        $this->assertStringContainsString('inline-flex w-fit self-start', $source);
        $this->assertStringContainsString('snitch-format-tag w-fit self-start text-sm', $source);
        $this->assertStringNotContainsString('snitch-format-tag text-xs', $source);
    }

    public function test_non_admin_cannot_post_generate(): void
    {
        $user = User::factory()->create(['email' => 'customer@example.com']);
        BrandProfile::factory()->for($user)->create();

        $this->actingAs($user)
            ->post(route('brief.generate'))
            ->assertForbidden();
    }

    public function test_dashboard_hides_brief_panel_without_brief(): void
    {
        $user = User::factory()->create();
        BrandProfile::factory()->for($user)->create();

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard')
                ->where('weekly_brief', null)
            );
    }

    public function test_dashboard_shows_brief_panel_when_ready(): void
    {
        $user = User::factory()->create();
        BrandProfile::factory()->for($user)->create();
        $brief = WeeklyBrief::factory()->for($user)->create([
            'status' => 'ready',
            'week_start' => app(WeeklyBriefGenerator::class)->currentWeekStart()->toDateString(),
        ]);
        $brief->ideas()->create([
            'position' => 1,
            'format' => 'Reel',
            'hook' => 'Open on proof',
            'visual' => 'Film the counter at open',
            'caption_angle' => 'Angle',
            'cta' => 'CTA',
            'hashtags' => ['#a', '#b', '#c'],
            'recommended_day' => 'Mon',
            'recommended_hour' => 9,
            'inspired_by_post_ids' => [],
            'why' => 'Because',
        ]);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard')
                ->where('weekly_brief.id', $brief->id)
                ->where('weekly_brief.hook', 'Open on proof')
                ->where('weekly_brief.ideas.0.format', 'Reel')
                ->where('weekly_brief.ideas.0.hook', 'Open on proof')
                ->where('weekly_brief.ideas.0.slot', 'Mon 09:00')
                ->where('weekly_brief.ideas.0.visual', 'Film the counter at open')
                ->where('weekly_brief.ideas.0.caption_angle', 'Angle')
                ->where('weekly_brief.ideas.0.cta', 'CTA')
            );
    }

    public function test_mark_idea_used_toggles(): void
    {
        $user = User::factory()->create();
        BrandProfile::factory()->for($user)->create();
        $brief = WeeklyBrief::factory()->for($user)->create();
        $idea = $brief->ideas()->create([
            'position' => 1,
            'format' => 'Reel',
            'hook' => 'Hook',
            'caption_angle' => 'Angle',
            'cta' => 'CTA',
            'hashtags' => ['#a', '#b', '#c'],
            'recommended_day' => 'Mon',
            'recommended_hour' => 9,
            'inspired_by_post_ids' => [],
            'why' => 'Because numbers',
        ]);

        $this->actingAs($user)
            ->post(route('brief.ideas.used', $idea))
            ->assertRedirect();

        $this->assertNotNull($idea->fresh()->used_at);

        $this->actingAs($user)
            ->post(route('brief.ideas.used', $idea))
            ->assertRedirect();

        $this->assertNull($idea->fresh()->used_at);
    }

    private function fakeLlm(): void
    {
        Http::fake([
            'https://nano-gpt.test/api/v1/chat/completions' => Http::response([
                'choices' => [[
                    'message' => [
                        'content' => json_encode([
                            'ideas' => [
                                [
                                    'format' => 'Reel',
                                    'hook' => 'Open on the proof',
                                    'caption_angle' => 'Show the swap in 15 seconds',
                                    'cta' => 'Save this',
                                    'hashtags' => ['#fitness', '#community', '#london'],
                                    'recommended_day' => 'Tue',
                                    'recommended_hour' => 11,
                                    'inspired_by_post_ids' => [],
                                    'why' => '@rival hit 3.2x usual on this format.',
                                ],
                                [
                                    'format' => 'Carousel',
                                    'hook' => 'Three mistakes',
                                    'caption_angle' => 'List the traps then the fix',
                                    'cta' => 'Comment 1 2 or 3',
                                    'hashtags' => ['#tips', '#growth', '#brand'],
                                    'recommended_day' => 'Thu',
                                    'recommended_hour' => 18,
                                    'inspired_by_post_ids' => [],
                                    'why' => 'Carousels from rivals cleared 2x usual.',
                                ],
                                [
                                    'format' => 'Image',
                                    'hook' => 'One bold claim',
                                    'caption_angle' => 'Single visual with a tight caption',
                                    'cta' => 'Share with a teammate',
                                    'hashtags' => ['#brand', '#content', '#social'],
                                    'recommended_day' => 'Sat',
                                    'recommended_hour' => 10,
                                    'inspired_by_post_ids' => [],
                                    'why' => 'Still images punched above usual last month.',
                                ],
                            ],
                        ]),
                    ],
                ]],
            ]),
        ]);
    }

    /**
     * @return list<int>
     */
    private function seedEnoughCompetitorData(User $user): array
    {
        $base = CarbonImmutable::now('Europe/London')->subDays(25);
        $winnerIds = [];

        for ($a = 0; $a < 2; $a++) {
            $rival = TrackedAccount::factory()->for($user)->create([
                'handle' => 'rival'.$a,
                'is_own_account' => false,
                'kind' => TrackedAccountKind::Competitor,
            ]);

            for ($i = 0; $i < 12; $i++) {
                $post = Post::factory()->forAccount($rival)->create([
                    'posted_at' => $base->addDays($i)->addHours($a),
                    'caption' => 'Training day #fitness #community',
                    'metrics' => ['views' => 1000, 'likes' => 40, 'comments' => 4, 'shares' => 0],
                ]);
                PostAnalysis::factory()->for($post)->create([
                    'status' => AnalysisStatus::Completed,
                    'hook' => 'Steady open',
                ]);
            }

            // Three clear winners per rival (>= 2x usual on interactions).
            for ($w = 0; $w < 3; $w++) {
                $winner = Post::factory()->forAccount($rival)->create([
                    'posted_at' => $base->addDays(20 + $w)->addHours($a),
                    'caption' => 'Big session #fitness #london',
                    'metrics' => ['views' => 8000, 'likes' => 320, 'comments' => 40, 'shares' => 5],
                ]);
                PostAnalysis::factory()->for($winner)->create([
                    'status' => AnalysisStatus::Completed,
                    'hook' => 'Open on the sweat',
                ]);
                $winnerIds[] = (int) $winner->id;
            }
        }

        return $winnerIds;
    }
}
