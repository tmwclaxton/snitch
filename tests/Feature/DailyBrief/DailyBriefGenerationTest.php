<?php

namespace Tests\Feature\DailyBrief;

use App\Enums\Platform;
use App\Enums\PostType;
use App\Enums\TrackedAccountKind;
use App\Mail\DailyBriefMail;
use App\Models\BrandProfile;
use App\Models\DailyBrief;
use App\Models\FollowerSnapshot;
use App\Models\Post;
use App\Models\TrackedAccount;
use App\Models\User;
use App\Services\Brief\DailyBriefFactsBuilder;
use App\Services\Brief\DailyBriefGenerator;
use App\Services\Brief\DailyBriefValidator;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Sleep;
use Tests\TestCase;

class DailyBriefGenerationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'snitch.nanogpt.api_key' => 'test-key',
            'snitch.nanogpt.base_url' => 'https://nano-gpt.test/api/v1',
            'snitch.daily_brief.model' => 'test-model',
            'snitch.brief.model' => 'test-model',
        ]);
    }

    public function test_generator_stores_one_free_brief_per_user_per_day(): void
    {
        $this->fakeValidLlm();

        $user = User::factory()->create(['daily_brief_enabled' => true]);
        BrandProfile::factory()->for($user)->create(['name' => 'Let\'s Go Social']);
        $this->seedAccounts($user);

        $generator = app(DailyBriefGenerator::class);
        $first = $generator->generate($user);
        $second = $generator->generate($user);

        $this->assertTrue($first->was_free);
        $this->assertSame(0.0, (float) $first->credits_charged_pence);
        $this->assertSame('ready', $first->status);
        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, DailyBrief::query()->where('user_id', $user->id)->count());
        $this->assertGreaterThanOrEqual(3, count($first->payload['actions'] ?? []));
        $this->assertLessThanOrEqual(5, count($first->payload['actions'] ?? []));

        $change = collect($first->payload['big_numbers'] ?? [])->firstWhere('label', 'Change since yesterday');
        $this->assertSame('New', $change['value'] ?? null);
        $this->assertStringContainsString('Daily tracking started', (string) ($change['note'] ?? ''));
        $this->assertStringContainsString('first comparison tomorrow', (string) ($change['note'] ?? ''));
        $this->assertStringNotContainsString('Daily tracking starts today', (string) ($change['value'] ?? ''));
    }

    public function test_force_updates_in_place(): void
    {
        $this->fakeValidLlm();

        $user = User::factory()->create(['daily_brief_enabled' => true]);
        BrandProfile::factory()->for($user)->create();
        $this->seedAccounts($user);

        $generator = app(DailyBriefGenerator::class);
        $first = $generator->generate($user);
        $second = $generator->generate($user, force: true);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, DailyBrief::query()->where('user_id', $user->id)->count());
    }

    public function test_llm_retries_on_429_then_succeeds(): void
    {
        Sleep::fake();

        $user = User::factory()->create(['daily_brief_enabled' => true]);
        BrandProfile::factory()->for($user)->create();
        $this->seedAccounts($user);

        Http::fake([
            'https://nano-gpt.test/api/v1/chat/completions' => Http::sequence()
                ->push('rate limited', 429, ['Retry-After' => '2'])
                ->push($this->llmResponse([
                    'headline' => 'Post a Reel today at 20:00.',
                    'actions' => [
                        ['title' => 'One', 'why' => 'why', 'how' => 'how', 'related_handles' => [], 'related_post_ids' => []],
                        ['title' => 'Two', 'why' => 'why', 'how' => 'how', 'related_handles' => [], 'related_post_ids' => []],
                        ['title' => 'Three', 'why' => 'why', 'how' => 'how', 'related_handles' => [], 'related_post_ids' => []],
                    ],
                    'own_summary' => 'ok',
                    'competitor_summary' => 'ok',
                    'watch' => [],
                ])),
        ]);

        $brief = app(DailyBriefGenerator::class)->generate($user);

        $this->assertFalse($brief->payload['validation']['used_fallback'] ?? true);
        $this->assertSame('Post a Reel today at 20:00.', $brief->headline);
        Http::assertSentCount(2);
        Sleep::assertSlept(fn ($interval): bool => (int) $interval->totalSeconds === 2, 1);
    }

    public function test_force_does_not_replace_an_llm_brief_with_fallback(): void
    {
        Sleep::fake();
        $this->fakeValidLlm();

        $user = User::factory()->create(['daily_brief_enabled' => true]);
        BrandProfile::factory()->for($user)->create();
        $this->seedAccounts($user);

        $generator = app(DailyBriefGenerator::class);
        $first = $generator->generate($user);

        Http::fake([
            'https://nano-gpt.test/api/v1/chat/completions' => Http::response('rate limited', 429, ['Retry-After' => '0']),
        ]);

        $second = $generator->generate($user, force: true);

        $this->assertSame($first->id, $second->id);
        $this->assertSame($first->headline, $second->headline);
        $this->assertFalse($second->payload['validation']['used_fallback'] ?? true);
        // Allow a 1s clock skew between read and compare in CI.
        $this->assertNotNull($first->generated_at);
        $this->assertNotNull($second->generated_at);
        $this->assertLessThanOrEqual(1, abs($first->generated_at->diffInSeconds($second->generated_at)));
    }

    public function test_keep_existing_sanitizes_stored_post_ids_and_logs_diagnostics(): void
    {
        Sleep::fake();
        Event::fake([MessageLogged::class]);

        $user = User::factory()->create(['daily_brief_enabled' => true]);
        BrandProfile::factory()->for($user)->create();
        $this->seedAccounts($user);

        $brief = DailyBrief::factory()->for($user)->create([
            'status' => 'ready',
            'headline' => 'Post a Reel today at 20:00.',
            'brief_date' => now('Europe/London')->toDateString(),
            'model' => 'test-model',
            'llm_attempts' => 1,
            'facts' => [
                'allowed_handles' => ['goodgym', 'letsgosocialuk', 'onehousesocialclub'],
                'allowed_post_ids' => [218],
                'borrowed_competitor_names' => ['Jessie'],
                'own' => ['handle' => 'letsgosocialuk', 'followers_now' => 97],
                'top_competitor_hit_24h' => [
                    'post_id' => 218,
                    'handle' => 'goodgym',
                    'format' => 'Reel',
                    'hook' => 'Living Room Listens',
                    'times_usual' => 1.0,
                ],
                'competitors' => [],
            ],
            'payload' => [
                'headline' => 'Post a Reel today at 20:00.',
                'own_summary' => 'ok',
                'competitor_summary' => 'ok',
                'actions' => [
                    [
                        'title' => 'Comment',
                        'why' => 'why',
                        'how' => 'Leave a comment under (post_id 218)',
                        'related_handles' => ['goodgym'],
                        'related_post_ids' => [218],
                    ],
                    ['title' => 'Two', 'why' => 'why', 'how' => 'how', 'related_handles' => [], 'related_post_ids' => []],
                    ['title' => 'Three', 'why' => 'why', 'how' => 'how', 'related_handles' => [], 'related_post_ids' => []],
                ],
                'unused_weekly_ideas' => [
                    ['position' => 1, 'format' => 'Reel', 'hook' => "Jessie\u{2019}s story: nervous, alone, now a regular"],
                ],
                'watch' => [],
                'validation' => ['used_fallback' => false, 'diagnostics' => []],
            ],
            'generated_at' => now()->subHour(),
        ]);

        Http::fake([
            'https://nano-gpt.test/api/v1/chat/completions' => Http::response('rate limited', 429, ['Retry-After' => '0']),
        ]);

        $second = app(DailyBriefGenerator::class)->generate($user, force: true);

        $this->assertSame($brief->id, $second->id);
        $how = (string) ($second->payload['actions'][0]['how'] ?? '');
        $this->assertStringNotContainsString('post_id', $how);
        $this->assertStringContainsString('Living Room Listens', $how);
        $this->assertStringNotContainsString('Jessie', (string) ($second->payload['unused_weekly_ideas'][0]['hook'] ?? ''));
        $this->assertStringContainsString("member's story", (string) ($second->payload['unused_weekly_ideas'][0]['hook'] ?? ''));
        Event::assertDispatched(MessageLogged::class, function (MessageLogged $event): bool {
            return $event->level === 'warning'
                && str_contains($event->message, 'fallback skipped')
                && isset($event->context['diagnostics'])
                && is_array($event->context['diagnostics'])
                && $event->context['diagnostics'] !== [];
        });
    }

    public function test_validation_fallback_skip_logs_per_attempt_errors(): void
    {
        Sleep::fake();
        Event::fake([MessageLogged::class]);

        $user = User::factory()->create(['daily_brief_enabled' => true]);
        BrandProfile::factory()->for($user)->create();
        $this->seedAccounts($user);

        DailyBrief::factory()->for($user)->create([
            'status' => 'ready',
            'headline' => 'Keep this brief',
            'brief_date' => now('Europe/London')->toDateString(),
            'model' => 'test-model',
            'llm_attempts' => 1,
            'facts' => [
                'allowed_handles' => ['letsgosocialuk', 'goodgym', 'onehousesocialclub'],
                'allowed_post_ids' => [],
                'own' => ['handle' => 'letsgosocialuk', 'followers_now' => 97],
                'competitors' => [],
            ],
            'payload' => [
                'headline' => 'Keep this brief',
                'own_summary' => 'ok',
                'competitor_summary' => 'ok',
                'actions' => [
                    ['title' => 'One', 'why' => 'why', 'how' => 'how', 'related_handles' => [], 'related_post_ids' => []],
                    ['title' => 'Two', 'why' => 'why', 'how' => 'how', 'related_handles' => [], 'related_post_ids' => []],
                    ['title' => 'Three', 'why' => 'why', 'how' => 'how', 'related_handles' => [], 'related_post_ids' => []],
                ],
                'unused_weekly_ideas' => [],
                'watch' => [],
                'validation' => ['used_fallback' => false, 'diagnostics' => []],
            ],
            'generated_at' => now()->subHour(),
        ]);

        Http::fake([
            'https://nano-gpt.test/api/v1/chat/completions' => Http::sequence()
                ->push($this->llmResponse([
                    'headline' => 'Bad @ghostclub 99.9x',
                    'actions' => [
                        ['title' => 'Bad', 'why' => '99.9x', 'how' => 'No', 'related_handles' => ['ghostclub'], 'related_post_ids' => []],
                        ['title' => 'Two', 'why' => 'why', 'how' => 'how', 'related_handles' => [], 'related_post_ids' => []],
                        ['title' => 'Three', 'why' => 'why', 'how' => 'how', 'related_handles' => [], 'related_post_ids' => []],
                    ],
                    'own_summary' => 'ok',
                    'competitor_summary' => 'ok',
                    'watch' => [],
                ]))
                ->push($this->llmResponse([
                    'headline' => 'Still bad @ghostclub 88.8x',
                    'actions' => [
                        ['title' => 'Still bad', 'why' => '88.8x', 'how' => 'No', 'related_handles' => ['ghostclub'], 'related_post_ids' => []],
                        ['title' => 'Two', 'why' => 'why', 'how' => 'how', 'related_handles' => [], 'related_post_ids' => []],
                        ['title' => 'Three', 'why' => 'why', 'how' => 'how', 'related_handles' => [], 'related_post_ids' => []],
                    ],
                    'own_summary' => 'ok',
                    'competitor_summary' => 'ok',
                    'watch' => [],
                ])),
        ]);

        $brief = app(DailyBriefGenerator::class)->generate($user, force: true);

        $this->assertSame('Keep this brief', $brief->headline);
        Event::assertDispatched(MessageLogged::class, function (MessageLogged $event): bool {
            if ($event->level !== 'warning' || ! str_contains($event->message, 'fallback skipped')) {
                return false;
            }

            $diagnostics = $event->context['diagnostics'] ?? null;

            return is_array($diagnostics)
                && count($diagnostics) >= 2
                && ($diagnostics[0]['errors'] ?? []) !== []
                && ($diagnostics[1]['errors'] ?? []) !== [];
        });
    }

    public function test_sanitize_flag_rewrites_stored_brief_in_place(): void
    {
        $user = User::factory()->create(['daily_brief_enabled' => true]);
        BrandProfile::factory()->for($user)->create();
        $this->seedAccounts($user);

        $brief = DailyBrief::factory()->for($user)->create([
            'status' => 'ready',
            'headline' => 'Plan for today',
            'brief_date' => now('Europe/London')->toDateString(),
            'facts' => [
                'allowed_handles' => ['goodgym', 'letsgosocialuk'],
                'allowed_post_ids' => [218],
                'borrowed_competitor_names' => ['Jessie'],
                'own' => ['handle' => 'letsgosocialuk', 'followers_now' => 97],
                'top_competitor_hit_24h' => [
                    'post_id' => 218,
                    'handle' => 'goodgym',
                    'format' => 'Reel',
                    'hook' => 'Living Room Listens',
                    'times_usual' => 1.0,
                ],
                'competitors' => [],
            ],
            'payload' => [
                'headline' => 'Plan for today',
                'own_summary' => 'ok',
                'competitor_summary' => 'ok',
                'actions' => [
                    [
                        'title' => 'Comment',
                        'why' => 'why',
                        'how' => 'Reply under (post_id 218)',
                        'related_handles' => ['goodgym'],
                        'related_post_ids' => [218],
                    ],
                ],
                'unused_weekly_ideas' => [
                    ['position' => 1, 'format' => 'Reel', 'hook' => "Jessie\u{2019}s story: nervous, alone, now a regular"],
                ],
                'watch' => [],
                'validation' => ['used_fallback' => false, 'diagnostics' => []],
            ],
        ]);

        $this->artisan('snitch:generate-daily-briefs', [
            '--user' => $user->id,
            '--sanitize' => true,
            '--date' => $brief->brief_date?->toDateString(),
        ])->assertSuccessful();

        $brief->refresh();
        $this->assertStringNotContainsString('post_id', (string) ($brief->payload['actions'][0]['how'] ?? ''));
        $this->assertStringContainsString('Living Room Listens', (string) ($brief->payload['actions'][0]['how'] ?? ''));
        $this->assertStringNotContainsString('Jessie', (string) ($brief->payload['unused_weekly_ideas'][0]['hook'] ?? ''));
        $this->assertStringContainsString("member's story", (string) ($brief->payload['unused_weekly_ideas'][0]['hook'] ?? ''));
    }

    public function test_first_brief_still_uses_fallback_when_llm_fails(): void
    {
        Sleep::fake();
        Http::fake([
            'https://nano-gpt.test/api/v1/chat/completions' => Http::response('rate limited', 429, ['Retry-After' => '0']),
        ]);

        $user = User::factory()->create(['daily_brief_enabled' => true]);
        BrandProfile::factory()->for($user)->create();
        $this->seedAccounts($user);

        $brief = app(DailyBriefGenerator::class)->generate($user);

        $this->assertTrue($brief->payload['validation']['used_fallback'] ?? false);
        $this->assertNotSame('', $brief->headline);
    }

    public function test_validator_rejects_invented_handles_and_numbers_then_retries_and_falls_back(): void
    {
        $user = User::factory()->create(['daily_brief_enabled' => true]);
        BrandProfile::factory()->for($user)->create();
        $this->seedAccounts($user);

        Http::fake([
            'https://nano-gpt.test/api/v1/chat/completions' => Http::sequence()
                ->push($this->llmResponse([
                    'headline' => 'Post tonight because @ghostclub hit 99.9x.',
                    'actions' => [
                        ['title' => 'Copy @ghostclub', 'why' => '99.9x', 'how' => 'Invented', 'when' => null, 'format' => 'Reel', 'hook' => 'Hi', 'related_handles' => ['ghostclub'], 'related_post_ids' => [999999]],
                        ['title' => 'Second', 'why' => 'x', 'how' => 'x', 'when' => null, 'format' => 'Reel', 'hook' => null, 'related_handles' => [], 'related_post_ids' => []],
                        ['title' => 'Third', 'why' => 'x', 'how' => 'x', 'when' => null, 'format' => 'Reel', 'hook' => null, 'related_handles' => [], 'related_post_ids' => []],
                    ],
                    'own_summary' => 'Fine',
                    'competitor_summary' => 'Fine',
                    'watch' => ['Fine'],
                ]))
                ->push($this->llmResponse([
                    'headline' => 'Still invented @ghostclub and 88.8x',
                    'actions' => [
                        ['title' => 'Still bad', 'why' => '88.8x', 'how' => 'No', 'when' => null, 'format' => 'Reel', 'hook' => null, 'related_handles' => ['ghostclub'], 'related_post_ids' => []],
                        ['title' => 'Second', 'why' => 'x', 'how' => 'x', 'when' => null, 'format' => 'Reel', 'hook' => null, 'related_handles' => [], 'related_post_ids' => []],
                        ['title' => 'Third', 'why' => 'x', 'how' => 'x', 'when' => null, 'format' => 'Reel', 'hook' => null, 'related_handles' => [], 'related_post_ids' => []],
                    ],
                    'own_summary' => 'Fine',
                    'competitor_summary' => 'Fine',
                    'watch' => ['Fine'],
                ])),
        ]);

        $brief = app(DailyBriefGenerator::class)->generate($user);

        $this->assertSame(2, $brief->llm_attempts);
        $this->assertTrue($brief->payload['validation']['used_fallback'] ?? false);
        $this->assertGreaterThanOrEqual(3, count($brief->payload['actions'] ?? []));
        $this->assertDoesNotMatchRegularExpression('/@ghostclub/', json_encode($brief->payload['actions']) ?: '');
    }

    public function test_last_day_tile_uses_a_post_from_the_last_day_not_a_thirty_day_winner(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-05 08:00:00', 'Europe/London'));
        $this->fakeValidLlm();

        $user = User::factory()->create(['daily_brief_enabled' => true]);
        BrandProfile::factory()->for($user)->create();
        $onehouse = TrackedAccount::factory()->for($user)->create([
            'platform' => Platform::Instagram,
            'handle' => 'onehousesocialclub',
            'is_own_account' => false,
            'kind' => TrackedAccountKind::Competitor,
        ]);
        $sober = TrackedAccount::factory()->for($user)->create([
            'platform' => Platform::Instagram,
            'handle' => 'sobersocial_',
            'is_own_account' => false,
            'kind' => TrackedAccountKind::Competitor,
        ]);

        for ($i = 20; $i >= 11; $i--) {
            Post::factory()->forAccount($onehouse)->create([
                'posted_at' => CarbonImmutable::parse('2026-10-05', 'Europe/London')->subDays($i)->setTime(12, 0),
                'metrics' => ['likes' => 10, 'comments' => 0, 'views' => 0],
            ]);
        }

        Post::factory()->forAccount($onehouse)->create([
            'posted_at' => CarbonImmutable::parse('2026-10-02 12:00:00', 'Europe/London'),
            'metrics' => ['likes' => 59, 'comments' => 0, 'views' => 0],
        ]);
        Post::factory()->forAccount($sober)->create([
            'posted_at' => CarbonImmutable::parse('2026-10-04 19:35:00', 'Europe/London'),
            'metrics' => ['likes' => 4, 'comments' => 0, 'views' => 0],
        ]);

        $brief = app(DailyBriefGenerator::class)->generate($user);
        $tile = collect($brief->payload['big_numbers'] ?? [])->firstWhere('label', 'Competitor posts in the last day');

        $this->assertSame('1', $tile['value'] ?? null);
        $this->assertStringContainsString('@sobersocial_', (string) ($tile['note'] ?? ''));
        $this->assertStringNotContainsString('onehousesocialclub', (string) ($tile['note'] ?? ''));
        $this->assertSame('sobersocial_', $brief->facts['top_competitor_hit_24h']['handle'] ?? null);
        $this->assertSame('onehousesocialclub', $brief->facts['top_competitor_hit']['handle'] ?? null);
        $this->assertFalse(collect($brief->facts['cadence'] ?? [])->firstWhere('handle', 'onehousesocialclub')['posted_every_day_last_7'] ?? true);
    }

    public function test_week_best_post_facts_ignore_a_thirty_day_winner(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-06 08:00:00', 'Europe/London'));
        $this->fakeValidLlm();

        $user = User::factory()->create(['daily_brief_enabled' => true]);
        BrandProfile::factory()->for($user)->create();
        $friendship = TrackedAccount::factory()->for($user)->create([
            'platform' => Platform::Instagram,
            'handle' => 'great.friendship',
            'is_own_account' => false,
            'kind' => TrackedAccountKind::Competitor,
        ]);

        // 10 baseline posts so times_usual clears PI_MIN_PRIORS.
        for ($i = 0; $i < 10; $i++) {
            Post::factory()->forAccount($friendship)->create([
                'posted_at' => CarbonImmutable::parse('2026-09-01', 'Europe/London')->addDays($i)->setTime(12, 0),
                'metrics' => ['likes' => 10, 'comments' => 0, 'views' => 0],
            ]);
        }
        Post::factory()->forAccount($friendship)->create([
            'posted_at' => CarbonImmutable::parse('2026-09-21 12:00:00', 'Europe/London'),
            'metrics' => ['likes' => 30, 'comments' => 0, 'views' => 0],
        ]);
        Post::factory()->forAccount($friendship)->create([
            'posted_at' => CarbonImmutable::parse('2026-10-02 12:00:00', 'Europe/London'),
            'metrics' => ['likes' => 9, 'comments' => 0, 'views' => 0],
        ]);

        $facts = app(DailyBriefFactsBuilder::class)->build($user, CarbonImmutable::parse('2026-10-06', 'Europe/London')->startOfDay());

        $this->assertSame(3.0, (float) ($facts['top_competitor_hit']['times_usual'] ?? 0));
        $this->assertSame(0.9, (float) ($facts['top_competitor_hit_7d']['times_usual'] ?? 0));
        $this->assertSame(0.9, (float) ($facts['competitors'][0]['best_post_7d']['times_usual'] ?? 0));
        $this->assertNotSame(
            $facts['top_competitor_hit']['post_id'] ?? null,
            $facts['top_competitor_hit_7d']['post_id'] ?? null,
        );
    }

    public function test_times_usual_uses_full_history_baseline_not_forty_four_day_cutoff(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-15 08:00:00', 'Europe/London'));

        $user = User::factory()->create(['daily_brief_enabled' => true]);
        BrandProfile::factory()->for($user)->create();
        $own = TrackedAccount::factory()->for($user)->create([
            'platform' => Platform::Instagram,
            'handle' => 'letsgosocialuk',
            'display_name' => 'Toby',
            'is_own_account' => true,
            'kind' => TrackedAccountKind::Competitor,
        ]);

        // Older posts outside the old 44-day load window (brief_date - 44 ≈ 1 Sep).
        // Median of these (likes 16) is what the dashboard uses for ~1.4x.
        for ($i = 0; $i < 12; $i++) {
            Post::factory()->forAccount($own)->create([
                'posted_at' => CarbonImmutable::parse('2026-07-01', 'Europe/London')->addDays($i * 3)->setTime(12, 0),
                'type' => PostType::Carousel,
                'metrics' => ['likes' => 16, 'comments' => 0, 'views' => 0],
            ]);
        }

        // Only three posts inside the truncated window before the subject post.
        // Old brief maths: median 8 → 22/8 = 2.75 ≈ 2.8x.
        foreach (['2026-09-05', '2026-09-12', '2026-09-19'] as $day) {
            Post::factory()->forAccount($own)->create([
                'posted_at' => CarbonImmutable::parse($day.' 12:00:00', 'Europe/London'),
                'type' => PostType::Carousel,
                'metrics' => ['likes' => 8, 'comments' => 0, 'views' => 0],
            ]);
        }

        $escapeRoom = Post::factory()->forAccount($own)->create([
            'posted_at' => CarbonImmutable::parse('2026-09-27 12:00:00', 'Europe/London'),
            'type' => PostType::Carousel,
            'caption' => 'Escape room night',
            'metrics' => ['likes' => 22, 'comments' => 0, 'views' => 0],
        ]);

        $facts = app(DailyBriefFactsBuilder::class)->build(
            $user,
            CarbonImmutable::parse('2026-10-15', 'Europe/London')->startOfDay(),
        );

        $best30 = is_array($facts['own']['best_post_30d'] ?? null) ? $facts['own']['best_post_30d'] : null;
        $this->assertNotNull($best30);
        $this->assertSame((int) $escapeRoom->id, (int) ($best30['post_id'] ?? 0));
        $this->assertSame(1.4, (float) ($best30['times_usual'] ?? 0));
        $this->assertSame('1.4 times their usual', (string) ($best30['times_usual_label'] ?? ''));
        $this->assertGreaterThanOrEqual(10, (int) ($best30['prior_n'] ?? 0));
        $this->assertFalse((bool) ($best30['times_usual_early'] ?? true));
    }

    public function test_cadence_gaps_mark_a_quieter_competitor_as_longest(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-06 08:00:00', 'Europe/London'));

        $user = User::factory()->create(['daily_brief_enabled' => true]);
        BrandProfile::factory()->for($user)->create();
        $own = TrackedAccount::factory()->for($user)->create([
            'platform' => Platform::Instagram,
            'handle' => 'letsgosocialuk',
            'is_own_account' => true,
            'kind' => TrackedAccountKind::Competitor,
        ]);
        $fuss = TrackedAccount::factory()->for($user)->create([
            'platform' => Platform::Instagram,
            'handle' => 'fuss.london',
            'is_own_account' => false,
            'kind' => TrackedAccountKind::Competitor,
        ]);

        Post::factory()->forAccount($own)->create([
            'posted_at' => CarbonImmutable::parse('2026-10-01 12:00:00', 'Europe/London'),
            'metrics' => ['likes' => 8, 'comments' => 0, 'views' => 0],
        ]);
        Post::factory()->forAccount($fuss)->create([
            'posted_at' => CarbonImmutable::parse('2026-09-24 12:00:00', 'Europe/London'),
            'metrics' => ['likes' => 6, 'comments' => 0, 'views' => 0],
        ]);

        $facts = app(DailyBriefFactsBuilder::class)->build($user, CarbonImmutable::parse('2026-10-06', 'Europe/London')->startOfDay());

        $this->assertSame(5, $facts['cadence_gaps']['own_days'] ?? null);
        $this->assertSame('fuss.london', $facts['cadence_gaps']['longest_handle'] ?? null);
        $this->assertSame(12, $facts['cadence_gaps']['longest_days'] ?? null);
        $this->assertFalse($facts['cadence_gaps']['own_is_longest'] ?? true);
    }

    public function test_validator_rewrites_a_thirty_day_winner_used_as_this_weeks_best(): void
    {
        $facts = [
            'allowed_handles' => ['great.friendship'],
            'allowed_post_ids' => [10],
            'brief_date' => '2026-10-06',
            'own' => ['handle' => 'letsgosocialuk', 'followers_now' => 97],
            'competitors' => [[
                'handle' => 'great.friendship',
                'posts_last_7d' => [[
                    'post_id' => 10,
                    'times_usual' => 0.9,
                    'format' => 'Reel',
                ]],
                'best_post_7d' => ['post_id' => 10, 'times_usual' => 0.9],
            ]],
            'top_competitor_hit' => ['post_id' => 99, 'times_usual' => 3.0, 'handle' => 'great.friendship'],
            'top_competitor_hit_7d' => ['post_id' => 10, 'times_usual' => 0.9, 'handle' => 'great.friendship'],
        ];

        $actions = [
            ['title' => 'One', 'why' => 'why', 'how' => 'how', 'related_handles' => [], 'related_post_ids' => []],
            ['title' => 'Two', 'why' => 'why', 'how' => 'how', 'related_handles' => [], 'related_post_ids' => []],
            ['title' => 'Three', 'why' => 'why', 'how' => 'how', 'related_handles' => [], 'related_post_ids' => []],
        ];

        $result = app(DailyBriefValidator::class)->validate([
            'headline' => 'Plan for today',
            'actions' => $actions,
            'own_summary' => 'ok',
            'competitor_summary' => 'In the last 7 days @great.friendship\'s best post got 3.0 times usual engagement.',
            'watch' => [],
        ], $facts);

        $this->assertTrue($result['ok'], implode('; ', $result['errors']));
        $this->assertStringContainsString('0.9', $result['output']['competitor_summary']);
        $this->assertStringNotContainsString('3.0', $result['output']['competitor_summary']);

        $allowed = app(DailyBriefValidator::class)->validate([
            'headline' => 'Plan for today',
            'actions' => $actions,
            'own_summary' => 'ok',
            'competitor_summary' => 'In the last 7 days @great.friendship\'s best post got 0.9 times usual engagement.',
            'watch' => [],
        ], $facts);

        $this->assertTrue($allowed['ok'], implode('; ', $allowed['errors']));
    }

    public function test_validator_rejects_a_false_longest_gap_claim(): void
    {
        $facts = [
            'allowed_handles' => ['letsgosocialuk', 'fuss.london'],
            'allowed_post_ids' => [],
            'brief_date' => '2026-10-06',
            'own' => ['handle' => 'letsgosocialuk', 'followers_now' => 97, 'days_since_last_post' => 5],
            'competitors' => [[
                'handle' => 'fuss.london',
                'days_since_last_post' => 12,
                'posts_last_7d_count' => 0,
            ]],
            'cadence_gaps' => [
                'own_days' => 5,
                'longest_handle' => 'fuss.london',
                'longest_days' => 12,
                'own_is_longest' => false,
            ],
        ];

        $rejected = app(DailyBriefValidator::class)->validate([
            'headline' => 'Plan for today',
            'actions' => [
                ['title' => 'One', 'why' => 'why', 'how' => 'how', 'related_handles' => [], 'related_post_ids' => []],
                ['title' => 'Two', 'why' => 'why', 'how' => 'how', 'related_handles' => [], 'related_post_ids' => []],
                ['title' => 'Three', 'why' => 'why', 'how' => 'how', 'related_handles' => [], 'related_post_ids' => []],
            ],
            'own_summary' => 'Your 5-day posting gap is the longest among competitors.',
            'competitor_summary' => 'ok',
            'watch' => [],
        ], $facts);

        $this->assertFalse($rejected['ok']);
        $this->assertTrue(collect($rejected['errors'])->contains(
            fn (string $error): bool => str_contains($error, 'longest-gap claim is false'),
        ));

        $facts['cadence_gaps'] = [
            'own_days' => 12,
            'longest_handle' => 'letsgosocialuk',
            'longest_days' => 12,
            'own_is_longest' => true,
        ];
        $facts['own']['days_since_last_post'] = 12;

        $allowed = app(DailyBriefValidator::class)->validate([
            'headline' => 'Plan for today',
            'actions' => [
                ['title' => 'One', 'why' => 'why', 'how' => 'how', 'related_handles' => [], 'related_post_ids' => []],
                ['title' => 'Two', 'why' => 'why', 'how' => 'how', 'related_handles' => [], 'related_post_ids' => []],
                ['title' => 'Three', 'why' => 'why', 'how' => 'how', 'related_handles' => [], 'related_post_ids' => []],
            ],
            'own_summary' => 'Your 12-day posting gap is the longest among competitors.',
            'competitor_summary' => 'ok',
            'watch' => [],
        ], $facts);

        $this->assertTrue($allowed['ok'], implode('; ', $allowed['errors']));

        $named = app(DailyBriefValidator::class)->validate([
            'headline' => 'Plan for today',
            'actions' => [
                ['title' => 'One', 'why' => 'why', 'how' => 'how', 'related_handles' => [], 'related_post_ids' => []],
                ['title' => 'Two', 'why' => 'why', 'how' => 'how', 'related_handles' => [], 'related_post_ids' => []],
                ['title' => 'Three', 'why' => 'why', 'how' => 'how', 'related_handles' => [], 'related_post_ids' => []],
            ],
            'own_summary' => '@fuss.london has the longest posting gap among competitors.',
            'competitor_summary' => 'ok',
            'watch' => [],
        ], [
            'allowed_handles' => ['letsgosocialuk', 'fuss.london'],
            'allowed_post_ids' => [],
            'brief_date' => '2026-10-06',
            'own' => ['handle' => 'letsgosocialuk', 'followers_now' => 97, 'days_since_last_post' => 5],
            'competitors' => [[
                'handle' => 'fuss.london',
                'days_since_last_post' => 12,
                'posts_last_7d_count' => 0,
            ]],
            'cadence_gaps' => [
                'own_days' => 5,
                'longest_handle' => 'fuss.london',
                'longest_days' => 12,
                'own_is_longest' => false,
            ],
        ]);

        $this->assertTrue($named['ok'], implode('; ', $named['errors']));
    }

    public function test_last_day_tile_says_none_when_no_competitor_posted(): void
    {
        $payload = app(DailyBriefGenerator::class)->assemblePayload([
            'own' => null,
            'format_mix' => ['competitor_posts_24h' => 0, 'competitors_7d' => []],
            'top_competitor_hit' => [
                'handle' => 'onehousesocialclub',
                'times_usual_label' => '5.9 times their usual',
            ],
            'top_competitor_hit_24h' => null,
            'competitors' => [],
            'unused_weekly_ideas' => [],
            'best_times' => ['thin' => true, 'weekday_evening_block' => 'weekday evenings'],
            'ads_empty' => true,
            'brief_date' => '2026-10-05',
        ], [
            'headline' => 'Plan',
            'actions' => [],
            'own_summary' => '',
            'competitor_summary' => '',
            'watch' => [],
        ], [], true);

        $tile = collect($payload['big_numbers'] ?? [])->firstWhere('label', 'Competitor posts in the last day');

        $this->assertSame('0', $tile['value'] ?? null);
        $this->assertSame('none in the last day', $tile['note'] ?? null);
    }

    public function test_validator_rejects_daily_claims_the_cadence_does_not_support(): void
    {
        $facts = [
            'allowed_handles' => ['goodgym', 'sobersocial_'],
            'allowed_post_ids' => [10],
            'cadence' => [
                [
                    'handle' => 'goodgym',
                    'posts_last_7d' => 6,
                    'days_since_last_post' => 3,
                    'distinct_days_posted_last_7' => 6,
                    'posted_every_day_last_7' => false,
                    'posted_almost_daily_last_7' => true,
                ],
                [
                    'handle' => 'sobersocial_',
                    'posts_last_7d' => 4,
                    'days_since_last_post' => 1,
                    'distinct_days_posted_last_7' => 4,
                    'posted_every_day_last_7' => false,
                    'posted_almost_daily_last_7' => false,
                ],
            ],
        ];

        $actions = [
            ['title' => 'One', 'why' => 'why', 'how' => 'how', 'related_handles' => [], 'related_post_ids' => []],
            ['title' => 'Two', 'why' => 'why', 'how' => 'how', 'related_handles' => [], 'related_post_ids' => []],
            ['title' => 'Three', 'why' => 'why', 'how' => 'how', 'related_handles' => [], 'related_post_ids' => []],
        ];

        // Unsupported "posts daily" is rewritten so the brief can keep the rest of the copy.
        $result = app(DailyBriefValidator::class)->validate([
            'headline' => 'Plan for today',
            'actions' => $actions,
            'own_summary' => 'ok',
            'competitor_summary' => 'Goodgym posts daily but hides likes',
            'watch' => ['sobersocial_ posts daily'],
        ], $facts);

        $this->assertTrue($result['ok'], implode('; ', $result['errors']));
        $this->assertStringNotContainsString('posts daily', $result['output']['competitor_summary']);
        $this->assertStringNotContainsString('posts daily', $result['output']['watch'][0] ?? '');

        $rawErrors = app(DailyBriefValidator::class)->unsupportedDailyClaims(
            'Goodgym posts daily but hides likes',
            $facts,
        );
        $this->assertContains('unsupported daily claim for @goodgym', $rawErrors);

        $facts['cadence'][0]['posted_every_day_last_7'] = true;
        $ok = app(DailyBriefValidator::class)->validate([
            'headline' => 'Plan for today',
            'actions' => $actions,
            'own_summary' => 'ok',
            'competitor_summary' => 'Goodgym posts daily but hides likes',
            'watch' => ['Keep an eye on @sobersocial_'],
        ], $facts);

        $this->assertTrue($ok['ok'], implode('; ', $ok['errors']));
        $this->assertStringContainsString('posts daily', $ok['output']['competitor_summary']);
    }

    public function test_validator_rewrites_daily_claims_without_falling_back(): void
    {
        $user = User::factory()->create(['daily_brief_enabled' => true]);
        BrandProfile::factory()->for($user)->create();
        $this->seedAccounts($user);

        Http::fake([
            'https://nano-gpt.test/api/v1/chat/completions' => Http::response($this->llmResponse([
                'headline' => 'Plan for today',
                'actions' => [
                    ['title' => 'One', 'why' => 'why', 'how' => 'how', 'related_handles' => [], 'related_post_ids' => []],
                    ['title' => 'Two', 'why' => 'why', 'how' => 'how', 'related_handles' => [], 'related_post_ids' => []],
                    ['title' => 'Three', 'why' => 'why', 'how' => 'how', 'related_handles' => [], 'related_post_ids' => []],
                ],
                'own_summary' => 'ok',
                'competitor_summary' => 'Goodgym posts daily but hides likes',
                'watch' => ['ok'],
            ])),
        ]);

        $brief = app(DailyBriefGenerator::class)->generate($user);

        $this->assertSame(1, $brief->llm_attempts);
        $this->assertFalse($brief->payload['validation']['used_fallback'] ?? true);
        $this->assertStringNotContainsString('posts daily', (string) ($brief->payload['competitor_summary'] ?? ''));
        $this->assertStringContainsString('Goodgym posts but hides likes', (string) ($brief->payload['competitor_summary'] ?? ''));
    }

    public function test_facts_spell_out_cadence_for_each_account(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-05 08:00:00', 'Europe/London'));

        $user = User::factory()->create(['daily_brief_enabled' => true]);
        BrandProfile::factory()->for($user)->create();
        $goodgym = TrackedAccount::factory()->for($user)->create([
            'platform' => Platform::Instagram,
            'handle' => 'goodgym',
            'is_own_account' => false,
            'kind' => TrackedAccountKind::Competitor,
        ]);

        foreach (['2026-09-29', '2026-09-30', '2026-10-01', '2026-10-02'] as $day) {
            Post::factory()->forAccount($goodgym)->create([
                'posted_at' => CarbonImmutable::parse($day.' 12:00:00', 'Europe/London'),
                'metrics' => ['likes' => 8, 'comments' => 0, 'views' => 0],
            ]);
        }

        $facts = app(DailyBriefFactsBuilder::class)->build($user, CarbonImmutable::parse('2026-10-05', 'Europe/London'));
        $row = collect($facts['cadence'] ?? [])->firstWhere('handle', 'goodgym');

        $this->assertSame(4, $row['posts_last_7d'] ?? null);
        $this->assertSame(3, $row['days_since_last_post'] ?? null);
        $this->assertSame(4, $row['distinct_days_posted_last_7'] ?? null);
        $this->assertFalse($row['posted_every_day_last_7'] ?? true);
        $this->assertFalse($row['posted_almost_daily_last_7'] ?? true);
    }

    public function test_validator_rejects_almost_daily_claims_the_cadence_does_not_support(): void
    {
        $facts = [
            'allowed_handles' => ['sobersocial_'],
            'allowed_post_ids' => [],
            'cadence' => [
                [
                    'handle' => 'sobersocial_',
                    'posts_last_7d' => 4,
                    'days_since_last_post' => 1,
                    'distinct_days_posted_last_7' => 4,
                    'posted_every_day_last_7' => false,
                    'posted_almost_daily_last_7' => false,
                ],
            ],
        ];

        $actions = [
            ['title' => 'One', 'why' => 'why', 'how' => 'how', 'related_handles' => [], 'related_post_ids' => []],
            ['title' => 'Two', 'why' => 'why', 'how' => 'how', 'related_handles' => [], 'related_post_ids' => []],
            ['title' => 'Three', 'why' => 'why', 'how' => 'how', 'related_handles' => [], 'related_post_ids' => []],
        ];

        // Rewrite softens unsupported almost-daily wording instead of discarding the brief.
        $result = app(DailyBriefValidator::class)->validate([
            'headline' => 'Plan for today',
            'actions' => $actions,
            'own_summary' => 'ok',
            'competitor_summary' => 'ok',
            'watch' => ['@sobersocial_ posts almost daily'],
        ], $facts);

        $this->assertTrue($result['ok'], implode('; ', $result['errors']));
        $this->assertStringNotContainsString('almost daily', $result['output']['watch'][0] ?? '');

        $facts['cadence'][0]['distinct_days_posted_last_7'] = 6;
        $facts['cadence'][0]['posts_last_7d'] = 6;
        $facts['cadence'][0]['posted_almost_daily_last_7'] = true;
        $ok = app(DailyBriefValidator::class)->validate([
            'headline' => 'Plan for today',
            'actions' => $actions,
            'own_summary' => 'ok',
            'competitor_summary' => 'ok',
            'watch' => ['@sobersocial_ posts almost daily'],
        ], $facts);

        $this->assertTrue($ok['ok'], implode('; ', $ok['errors']));
        $this->assertStringContainsString('almost daily', $ok['output']['watch'][0] ?? '');

        // Strict "posts daily" still fails even when almost-daily is true; rewrite softens it.
        $daily = app(DailyBriefValidator::class)->validate([
            'headline' => 'Plan for today',
            'actions' => $actions,
            'own_summary' => 'ok',
            'competitor_summary' => 'ok',
            'watch' => ['@sobersocial_ posts daily'],
        ], $facts);

        $this->assertTrue($daily['ok'], implode('; ', $daily['errors']));
        $this->assertStringNotContainsString('posts daily', $daily['output']['watch'][0] ?? '');
    }

    public function test_validator_rewrites_daily_like_watch_when_handle_has_few_posts(): void
    {
        $facts = [
            'allowed_handles' => ['sobersocial_', 'onehousesocialclub', 'letsgosocialuk', 'fuss.london'],
            'allowed_post_ids' => [],
            'brief_date' => '2026-10-12',
            'own' => [
                'handle' => 'letsgosocialuk',
                'days_since_last_post' => 11,
                'posts_last_7d_count' => 0,
            ],
            'competitors' => [
                [
                    'handle' => 'sobersocial_',
                    'posts_last_7d_count' => 3,
                    'sync_empty' => false,
                    'followers_change_7d' => [
                        'change' => 42,
                        'from_date' => '2026-10-05',
                        'label' => '+42 in 7 days',
                    ],
                    'cadence' => [
                        'posts_last_7d' => 3,
                        'days_since_last_post' => 1,
                        'distinct_days_posted_last_7' => 3,
                        'posted_every_day_last_7' => false,
                        'posted_almost_daily_last_7' => false,
                    ],
                ],
                [
                    'handle' => 'onehousesocialclub',
                    'posts_last_7d_count' => 6,
                    'sync_empty' => false,
                    'cadence' => [
                        'posts_last_7d' => 6,
                        'distinct_days_posted_last_7' => 6,
                        'posted_almost_daily_last_7' => true,
                        'posted_every_day_last_7' => false,
                    ],
                ],
                [
                    'handle' => 'fuss.london',
                    'posts_last_7d_count' => 0,
                    'sync_empty' => true,
                    'cadence' => [
                        'posts_last_7d' => 0,
                        'posted_every_day_last_7' => false,
                        'posted_almost_daily_last_7' => false,
                    ],
                ],
            ],
            'cadence' => [
                [
                    'handle' => 'sobersocial_',
                    'posts_last_7d' => 3,
                    'days_since_last_post' => 1,
                    'distinct_days_posted_last_7' => 3,
                    'posted_every_day_last_7' => false,
                    'posted_almost_daily_last_7' => false,
                ],
                [
                    'handle' => 'onehousesocialclub',
                    'posts_last_7d' => 6,
                    'distinct_days_posted_last_7' => 6,
                    'posted_almost_daily_last_7' => true,
                    'posted_every_day_last_7' => false,
                ],
            ],
            'cadence_gaps' => [
                'own_days' => 11,
                'longest_handle' => 'letsgosocialuk',
                'longest_days' => 11,
                'own_is_longest' => true,
            ],
        ];

        $actions = [
            [
                'title' => 'Close the gap',
                'why' => '@letsgosocialuk has the longest gap among all tracked accounts.',
                'how' => 'Post a Reel today.',
                'related_handles' => ['letsgosocialuk'],
                'related_post_ids' => [],
            ],
            ['title' => 'Two', 'why' => 'why', 'how' => 'how', 'related_handles' => [], 'related_post_ids' => []],
            ['title' => 'Three', 'why' => 'why', 'how' => 'how', 'related_handles' => [], 'related_post_ids' => []],
        ];

        $result = app(DailyBriefValidator::class)->validate([
            'headline' => 'Plan for today',
            'actions' => $actions,
            'own_summary' => '@letsgosocialuk has the longest gap among all tracked accounts.',
            'competitor_summary' => '@onehousesocialclub posts almost daily.',
            'watch' => ['sobersocial_\'s consistent daily-like posting and large follower growth (+42 in 7 days)'],
            'trends' => ['Watch sobersocial_ near-daily posting.'],
        ], $facts);

        $this->assertTrue($result['ok'], implode('; ', $result['errors']));
        $this->assertStringNotContainsString('daily-like', $result['output']['watch'][0] ?? '');
        $this->assertStringNotContainsString('near-daily', $result['output']['trends'][0] ?? '');
        $this->assertStringContainsString('almost daily', $result['output']['competitor_summary']);
        $this->assertStringContainsString('among accounts with posts', $result['output']['own_summary']);
        $this->assertStringContainsString('among accounts with posts', $result['output']['actions'][0]['why'] ?? '');
        $this->assertStringNotContainsString('among all tracked accounts', $result['output']['own_summary']);
    }

    public function test_validator_allows_brand_own_handle_even_when_missing_from_allowed_handles(): void
    {
        $result = app(DailyBriefValidator::class)->validate([
            'headline' => 'Plan for @letsgosocialuk',
            'actions' => [
                ['title' => 'Reply on @letsgosocialuk', 'why' => 'why', 'how' => 'how', 'related_handles' => ['letsgosocialuk'], 'related_post_ids' => []],
                ['title' => 'Two', 'why' => 'why', 'how' => 'how', 'related_handles' => [], 'related_post_ids' => []],
                ['title' => 'Three', 'why' => 'why', 'how' => 'how', 'related_handles' => [], 'related_post_ids' => []],
            ],
            'own_summary' => '@letsgosocialuk posted twice this week.',
            'competitor_summary' => 'ok',
            'watch' => ['Keep replies on @letsgosocialuk.'],
        ], [
            'allowed_handles' => ['goodgym'],
            'allowed_post_ids' => [],
            'brand' => ['own_handles' => ['letsgosocialuk']],
            'own' => null,
        ]);

        $this->assertTrue($result['ok'], implode('; ', $result['errors']));
    }

    public function test_generator_keeps_a_brief_that_mentions_the_brand_own_handle(): void
    {
        $user = User::factory()->create(['daily_brief_enabled' => true]);
        BrandProfile::factory()->for($user)->create([
            'name' => 'Let\'s Go Social',
            'own_handles' => ['instagram' => '@letsgosocialuk'],
        ]);
        TrackedAccount::factory()->for($user)->create([
            'platform' => Platform::Instagram,
            'handle' => 'goodgym',
            'is_own_account' => false,
            'kind' => TrackedAccountKind::Competitor,
            'followers' => 100,
            'last_sync_status' => 'success',
        ]);

        Http::fake([
            'https://nano-gpt.test/api/v1/chat/completions' => Http::response($this->llmResponse([
                'headline' => 'Plan for @letsgosocialuk',
                'actions' => [
                    ['title' => 'Reply on @letsgosocialuk', 'why' => 'Own account needs replies.', 'how' => 'Reply to comments.', 'related_handles' => ['letsgosocialuk'], 'related_post_ids' => []],
                    ['title' => 'Two', 'why' => 'why', 'how' => 'how', 'related_handles' => [], 'related_post_ids' => []],
                    ['title' => 'Three', 'why' => 'why', 'how' => 'how', 'related_handles' => [], 'related_post_ids' => []],
                ],
                'own_summary' => '@letsgosocialuk is the brand account to watch today.',
                'competitor_summary' => '@goodgym posted this week.',
                'watch' => ['Keep @letsgosocialuk replies tight'],
            ])),
        ]);

        $brief = app(DailyBriefGenerator::class)->generate($user);

        $this->assertContains('letsgosocialuk', $brief->facts['allowed_handles'] ?? []);
        $this->assertFalse($brief->payload['validation']['used_fallback'] ?? true);
        $this->assertStringContainsString('letsgosocialuk', (string) ($brief->payload['own_summary'] ?? ''));
        $this->assertFalse(collect($brief->payload['validation']['diagnostics'] ?? [])->flatten()->contains(
            fn (mixed $error): bool => is_string($error) && str_contains($error, 'unknown handle @letsgosocialuk'),
        ));
    }

    public function test_failed_sync_is_not_treated_as_quiet_or_empty(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-05 08:00:00', 'Europe/London'));

        $user = User::factory()->create(['daily_brief_enabled' => true]);
        BrandProfile::factory()->for($user)->create();
        $failed = TrackedAccount::factory()->for($user)->create([
            'platform' => Platform::Instagram,
            'handle' => 'fuss.london',
            'is_own_account' => false,
            'kind' => TrackedAccountKind::Competitor,
            'last_sync_status' => 'failed',
            'last_sync_error' => 'TikHub scrape failed.',
        ]);
        $quiet = TrackedAccount::factory()->for($user)->create([
            'platform' => Platform::Instagram,
            'handle' => 'london.theofflineclub',
            'is_own_account' => false,
            'kind' => TrackedAccountKind::Competitor,
            'last_sync_status' => 'empty',
        ]);
        $empty = TrackedAccount::factory()->for($user)->create([
            'platform' => Platform::Instagram,
            'handle' => 'ghostclub',
            'is_own_account' => false,
            'kind' => TrackedAccountKind::Competitor,
            'last_sync_status' => 'empty',
            'last_sync_error' => 'No recent posts found for this handle.',
        ]);

        Post::factory()->forAccount($failed)->create([
            'posted_at' => CarbonImmutable::parse('2026-09-10 12:00:00', 'Europe/London'),
            'metrics' => ['likes' => 4, 'comments' => 0, 'views' => 0],
        ]);
        Post::factory()->forAccount($quiet)->create([
            'posted_at' => CarbonImmutable::parse('2026-08-26 12:00:00', 'Europe/London'),
            'metrics' => ['likes' => 8, 'comments' => 0, 'views' => 0],
        ]);

        $facts = app(DailyBriefFactsBuilder::class)->build($user, CarbonImmutable::parse('2026-10-05', 'Europe/London'));
        $failedRow = collect($facts['competitors'] ?? [])->firstWhere('handle', 'fuss.london');
        $quietRow = collect($facts['competitors'] ?? [])->firstWhere('handle', 'london.theofflineclub');
        $emptyRow = collect($facts['competitors'] ?? [])->firstWhere('handle', 'ghostclub');

        $this->assertTrue($failedRow['sync_failed'] ?? false);
        $this->assertFalse($failedRow['quiet'] ?? true);
        $this->assertFalse($failedRow['sync_empty'] ?? true);
        $this->assertTrue($quietRow['quiet'] ?? false);
        $this->assertFalse($quietRow['sync_empty'] ?? true);
        $this->assertFalse($quietRow['sync_failed'] ?? true);
        $this->assertTrue($emptyRow['sync_empty'] ?? false);
        $this->assertFalse($emptyRow['quiet'] ?? true);

        $payload = app(DailyBriefGenerator::class)->assemblePayload($facts, [
            'headline' => 'Plan',
            'actions' => [],
            'own_summary' => '',
            'competitor_summary' => '',
            'watch' => [],
        ], [], true);

        $watch = implode("\n", $payload['watch'] ?? []);
        $this->assertStringContainsString('could not be refreshed', $watch);
        $this->assertStringNotContainsString('@fuss.london has been quiet', $watch);
        $this->assertStringContainsString('@london.theofflineclub has been quiet', $watch);

        $failedMove = collect($payload['competitor_moves'] ?? [])->firstWhere('handle', 'fuss.london');
        $this->assertTrue($failedMove['sync_failed'] ?? false);
        $this->assertFalse($failedMove['quiet'] ?? true);
        $this->assertFalse($failedMove['sync_empty'] ?? true);
    }

    public function test_validator_rewrites_internal_post_ids_in_copy(): void
    {
        $result = app(DailyBriefValidator::class)->validate([
            'headline' => 'Plan for today',
            'actions' => [
                [
                    'title' => 'Comment on their latest Reel',
                    'why' => 'why',
                    'how' => 'Leave genuine, non-spammy comments on post 218',
                    'related_handles' => ['goodgym'],
                    'related_post_ids' => [218],
                ],
                ['title' => 'Two', 'why' => 'why', 'how' => 'Reply under (post_id 218) tonight', 'related_handles' => [], 'related_post_ids' => [218]],
                ['title' => 'Three', 'why' => 'why', 'how' => 'Comment on post #218 tonight.', 'related_handles' => [], 'related_post_ids' => [218]],
                [
                    'title' => 'Four',
                    'why' => 'why',
                    'how' => 'Engage with post_id 218 and post-id 218 then (id 218).',
                    'related_handles' => [],
                    'related_post_ids' => [218],
                ],
            ],
            'own_summary' => 'ok',
            'competitor_summary' => 'ok',
            'watch' => [],
        ], [
            'allowed_handles' => ['goodgym'],
            'allowed_post_ids' => [218],
            'own' => ['handle' => 'letsgosocialuk', 'followers_now' => 97],
            'top_competitor_hit_24h' => [
                'post_id' => 218,
                'handle' => 'goodgym',
                'format' => 'Reel',
                'hook' => 'Living Room Listens',
            ],
        ]);

        $this->assertTrue($result['ok'], implode('; ', $result['errors']));
        $this->assertSame(
            "Leave genuine, non-spammy comments on their 'Living Room Listens' Reel",
            $result['output']['actions'][0]['how'] ?? null,
        );
        $this->assertSame(
            "Reply under their 'Living Room Listens' Reel tonight",
            $result['output']['actions'][1]['how'] ?? null,
        );
        $this->assertSame(
            "Comment on their 'Living Room Listens' Reel tonight.",
            $result['output']['actions'][2]['how'] ?? null,
        );
        $this->assertSame(
            "Engage with their 'Living Room Listens' Reel and their 'Living Room Listens' Reel then their 'Living Room Listens' Reel.",
            $result['output']['actions'][3]['how'] ?? null,
        );
        $this->assertSame([218], $result['output']['actions'][0]['related_post_ids'] ?? null);
        $copy = strtolower(collect($result['output']['actions'])->map(
            fn (array $action): string => implode(' ', [
                (string) ($action['title'] ?? ''),
                (string) ($action['why'] ?? ''),
                (string) ($action['how'] ?? ''),
                (string) ($action['hook'] ?? ''),
            ]),
        )->implode(' '));
        $this->assertStringNotContainsString('post 218', $copy);
        $this->assertStringNotContainsString('post #218', $copy);
        $this->assertStringNotContainsString('post_id', $copy);
        $this->assertStringNotContainsString('(id 218)', $copy);
        $this->assertStringNotContainsString('()', $copy);
    }

    public function test_validator_drops_parenthetical_post_ids_after_a_named_reel(): void
    {
        $facts = [
            'allowed_handles' => ['goodgym', 'onehousesocialclub'],
            'allowed_post_ids' => [218, 245],
            'own' => ['handle' => 'letsgosocialuk', 'followers_now' => 97],
            'top_competitor_hit_24h' => [
                'post_id' => 218,
                'handle' => 'goodgym',
                'format' => 'Reel',
                'hook' => 'POV: you found a social club tonight',
            ],
            'top_competitor_hit_7d' => [
                'post_id' => 245,
                'handle' => 'onehousesocialclub',
                'format' => 'Reel',
                'hook' => 'One of the best things about London',
            ],
        ];

        $result = app(DailyBriefValidator::class)->validate([
            'headline' => 'Plan for today',
            'actions' => [
                [
                    'title' => 'Comment on goodgym',
                    'why' => 'why',
                    'how' => 'Leave a genuine comment on their Reel (post_id 218) about joining a first meetup.',
                    'related_handles' => ['goodgym'],
                    'related_post_ids' => [218],
                ],
                [
                    'title' => 'Comment on onehousesocialclub',
                    'why' => 'why',
                    'how' => 'Leave a comment on their Reel (post_id 245) praising the welcome.',
                    'related_handles' => ['onehousesocialclub'],
                    'related_post_ids' => [245],
                ],
                [
                    'title' => 'Reply on the other Reel',
                    'why' => 'why',
                    'how' => 'Reply under post 218 tonight.',
                    'related_handles' => ['goodgym'],
                    'related_post_ids' => [218],
                ],
            ],
            'own_summary' => 'ok',
            'competitor_summary' => 'ok',
            'watch' => [],
        ], $facts);

        $this->assertTrue($result['ok'], implode('; ', $result['errors']));
        $this->assertSame(
            'Leave a genuine comment on their Reel about joining a first meetup.',
            $result['output']['actions'][0]['how'] ?? null,
        );
        $this->assertSame(
            'Leave a comment on their Reel praising the welcome.',
            $result['output']['actions'][1]['how'] ?? null,
        );
        $this->assertSame(
            "Reply under their 'POV: you found a social' Reel tonight.",
            $result['output']['actions'][2]['how'] ?? null,
        );
        foreach ($result['output']['actions'] as $action) {
            $how = (string) ($action['how'] ?? '');
            $this->assertStringNotContainsString('their their', $how);
            $this->assertDoesNotMatchRegularExpression('/\bReel\b.+\bReel\b/', $how);
            $this->assertStringNotContainsString('post_id', $how);
        }
    }

    public function test_validator_rewrites_link_in_bio_sticker_to_link_sticker(): void
    {
        $result = app(DailyBriefValidator::class)->validate([
            'headline' => 'Add a Link In Bio Sticker today',
            'actions' => [
                [
                    'title' => 'Post a Story with a CTA',
                    'why' => 'why',
                    'how' => 'Add a link in bio sticker pointing to the ticket page',
                    'related_handles' => [],
                    'related_post_ids' => [],
                ],
                ['title' => 'Two', 'why' => 'why', 'how' => 'how', 'related_handles' => [], 'related_post_ids' => []],
                ['title' => 'Three', 'why' => 'why', 'how' => 'how', 'related_handles' => [], 'related_post_ids' => []],
            ],
            'own_summary' => 'ok',
            'competitor_summary' => 'ok',
            'watch' => ['Try a LINK IN BIO STICKER on Stories'],
        ], [
            'allowed_handles' => ['letsgosocialuk'],
            'allowed_post_ids' => [],
            'own' => ['handle' => 'letsgosocialuk', 'followers_now' => 97],
        ]);

        $this->assertTrue($result['ok'], implode('; ', $result['errors']));
        $this->assertSame('Add a link sticker today', $result['output']['headline'] ?? null);
        $this->assertSame(
            'Add a link sticker pointing to the ticket page',
            $result['output']['actions'][0]['how'] ?? null,
        );
        $this->assertSame('Try a link sticker on Stories', $result['output']['watch'][0] ?? null);
        $this->assertStringNotContainsString('link in bio sticker', mb_strtolower(json_encode($result['output']) ?: ''));
    }

    public function test_validator_rewrites_borrowed_competitor_person_names(): void
    {
        $result = app(DailyBriefValidator::class)->validate([
            'headline' => 'Plan for today',
            'actions' => [
                [
                    'title' => "Film Jessie's story today",
                    'why' => 'why',
                    'how' => 'Open on Jessie looking nervous, then cut to the meetup',
                    'related_handles' => [],
                    'related_post_ids' => [],
                ],
                ['title' => 'Two', 'why' => 'why', 'how' => 'how', 'related_handles' => [], 'related_post_ids' => []],
                ['title' => 'Three', 'why' => 'why', 'how' => 'how', 'related_handles' => [], 'related_post_ids' => []],
            ],
            'own_summary' => 'ok',
            'competitor_summary' => 'ok',
            'watch' => [],
        ], [
            'allowed_handles' => ['letsgosocialuk', 'great.friendship'],
            'allowed_post_ids' => [],
            'borrowed_competitor_names' => ['Jessie'],
            'own' => ['handle' => 'letsgosocialuk', 'followers_now' => 97],
            'competitors' => [],
        ]);

        $this->assertTrue($result['ok'], implode('; ', $result['errors']));
        $this->assertSame("Film a member's story today", $result['output']['actions'][0]['title'] ?? null);
        $this->assertStringContainsString('a member looking nervous', $result['output']['actions'][0]['how'] ?? '');
        $this->assertStringNotContainsString('Jessie', json_encode($result['output']['actions']) ?: '');
    }

    public function test_validator_rewrites_standout_wording_for_about_usual_posts(): void
    {
        $facts = [
            'allowed_handles' => ['sobersocial_', 'onehousesocialclub'],
            'allowed_post_ids' => [50],
            'own' => ['handle' => 'letsgosocialuk', 'followers_now' => 97],
            'competitors' => [[
                'handle' => 'sobersocial_',
                'posts_last_7d' => [[
                    'post_id' => 50,
                    'handle' => 'sobersocial_',
                    'times_usual' => 1.0,
                    'format' => 'Reel',
                    'hook' => 'Traitors Dinner',
                ]],
                'best_post_7d' => [
                    'post_id' => 50,
                    'handle' => 'sobersocial_',
                    'times_usual' => 1.0,
                    'format' => 'Reel',
                    'hook' => 'Traitors Dinner',
                ],
            ]],
            'top_competitor_hit_7d' => [
                'post_id' => 50,
                'handle' => 'sobersocial_',
                'times_usual' => 1.0,
            ],
        ];

        $softened = app(DailyBriefValidator::class)->validate([
            'headline' => 'Plan for today',
            'actions' => [
                [
                    'title' => 'Comment on onehousesocialclub',
                    'why' => 'why',
                    'how' => "Reply under onehousesocialclub's top hit Reel",
                    'related_handles' => ['onehousesocialclub'],
                    'related_post_ids' => [],
                ],
                [
                    'title' => 'Check sobersocial_',
                    'why' => 'why',
                    'how' => "Look at sobersocial_'s top post from yesterday",
                    'related_handles' => ['sobersocial_'],
                    'related_post_ids' => [],
                ],
                ['title' => 'Three', 'why' => 'why', 'how' => 'how', 'related_handles' => [], 'related_post_ids' => []],
            ],
            'own_summary' => 'ok',
            'competitor_summary' => "@sobersocial_'s Traitors Dinner Reel was a standout.",
            'watch' => [],
        ], $facts);

        $this->assertTrue($softened['ok'], implode('; ', $softened['errors']));
        $this->assertSame(
            "@sobersocial_'s Traitors Dinner Reel.",
            $softened['output']['competitor_summary'] ?? null,
        );
        $this->assertSame(
            "Reply under onehousesocialclub's Reel",
            $softened['output']['actions'][0]['how'] ?? null,
        );
        $this->assertSame(
            "Look at sobersocial_'s post from yesterday",
            $softened['output']['actions'][1]['how'] ?? null,
        );

        $facts['competitors'][0]['posts_last_7d'][0]['times_usual'] = 1.5;
        $facts['competitors'][0]['best_post_7d']['times_usual'] = 1.5;
        $facts['top_competitor_hit_7d']['times_usual'] = 1.5;

        $allowed = app(DailyBriefValidator::class)->validate([
            'headline' => 'Plan for today',
            'actions' => [
                ['title' => 'One', 'why' => 'why', 'how' => 'how', 'related_handles' => [], 'related_post_ids' => []],
                ['title' => 'Two', 'why' => 'why', 'how' => 'how', 'related_handles' => [], 'related_post_ids' => []],
                ['title' => 'Three', 'why' => 'why', 'how' => 'how', 'related_handles' => [], 'related_post_ids' => []],
            ],
            'own_summary' => 'ok',
            'competitor_summary' => "@sobersocial_'s Traitors Dinner Reel was a standout at 1.5 times their usual.",
            'watch' => [],
        ], $facts);

        $this->assertTrue($allowed['ok'], implode('; ', $allowed['errors']));
        $this->assertStringContainsString('standout', (string) ($allowed['output']['competitor_summary'] ?? ''));
    }

    public function test_validator_rewrites_curly_apostrophe_borrowed_names(): void
    {
        $result = app(DailyBriefValidator::class)->validate([
            'headline' => 'Plan for today',
            'actions' => [
                [
                    'title' => 'Film today',
                    'why' => 'why',
                    'how' => "Jessie\u{2019}s story: nervous, alone, now a regular",
                    'related_handles' => [],
                    'related_post_ids' => [],
                ],
                ['title' => 'Two', 'why' => 'why', 'how' => 'how', 'related_handles' => [], 'related_post_ids' => []],
                ['title' => 'Three', 'why' => 'why', 'how' => 'how', 'related_handles' => [], 'related_post_ids' => []],
            ],
            'own_summary' => 'ok',
            'competitor_summary' => 'ok',
            'watch' => [],
        ], [
            'allowed_handles' => ['letsgosocialuk'],
            'allowed_post_ids' => [],
            'borrowed_competitor_names' => ['Jessie'],
            'own' => ['handle' => 'letsgosocialuk', 'followers_now' => 97],
            'competitors' => [],
        ]);

        $this->assertTrue($result['ok'], implode('; ', $result['errors']));
        $this->assertSame(
            "A member's story: nervous, alone, now a regular",
            $result['output']['actions'][0]['how'] ?? null,
        );
        $this->assertStringNotContainsString('Jessie', (string) ($result['output']['actions'][0]['how'] ?? ''));
    }

    public function test_validator_rejects_an_unknown_internal_post_id_in_copy(): void
    {
        $result = app(DailyBriefValidator::class)->validate([
            'headline' => 'Plan for today',
            'actions' => [
                ['title' => 'One', 'why' => 'why', 'how' => 'Comment on post 904', 'related_handles' => [], 'related_post_ids' => []],
                ['title' => 'Two', 'why' => 'why', 'how' => 'how', 'related_handles' => [], 'related_post_ids' => []],
                ['title' => 'Three', 'why' => 'why', 'how' => 'how', 'related_handles' => [], 'related_post_ids' => []],
            ],
            'own_summary' => 'ok',
            'competitor_summary' => 'ok',
            'watch' => [],
        ], [
            'allowed_handles' => ['letsgosocialuk'],
            'allowed_post_ids' => [10],
            'own' => ['followers_now' => 97],
        ]);

        $this->assertFalse($result['ok']);
        $this->assertTrue(collect($result['errors'])->contains(
            fn (string $error): bool => str_contains($error, 'internal post id in copy'),
        ));
    }

    public function test_validator_accepts_a_fact_number_with_a_trailing_period(): void
    {
        $result = app(DailyBriefValidator::class)->validate([
            'headline' => 'Plan for today',
            'actions' => [
                ['title' => 'One', 'why' => 'why', 'how' => 'how', 'related_handles' => [], 'related_post_ids' => []],
                ['title' => 'Two', 'why' => 'why', 'how' => 'how', 'related_handles' => [], 'related_post_ids' => []],
                ['title' => 'Three', 'why' => 'why', 'how' => 'how', 'related_handles' => [], 'related_post_ids' => []],
            ],
            'own_summary' => '@letsgosocialuk has 97. followers.',
            'competitor_summary' => 'ok',
            'watch' => [],
        ], [
            'allowed_handles' => ['letsgosocialuk'],
            'allowed_post_ids' => [],
            'own' => ['handle' => 'letsgosocialuk', 'followers_now' => 97],
            'brief_date' => '2026-10-06',
        ]);

        $this->assertTrue($result['ok'], implode('; ', $result['errors']));
    }

    public function test_validator_rejects_last_7_days_when_follower_from_date_is_older(): void
    {
        $result = app(DailyBriefValidator::class)->validate([
            'headline' => 'Plan for today',
            'actions' => [
                ['title' => 'One', 'why' => 'why', 'how' => 'how', 'related_handles' => [], 'related_post_ids' => []],
                ['title' => 'Two', 'why' => 'why', 'how' => 'how', 'related_handles' => [], 'related_post_ids' => []],
                ['title' => 'Three', 'why' => 'why', 'how' => 'how', 'related_handles' => [], 'related_post_ids' => []],
            ],
            'own_summary' => '@letsgosocialuk gained 4 in the last 7 days.',
            'competitor_summary' => 'ok',
            'watch' => [],
        ], [
            'allowed_handles' => ['letsgosocialuk'],
            'allowed_post_ids' => [],
            'brief_date' => '2026-10-06',
            'own' => [
                'handle' => 'letsgosocialuk',
                'followers_now' => 97,
                'followers_change_7d' => [
                    'change' => 4,
                    'from_date' => '2026-09-27',
                    'label' => '+4 since 27 September',
                ],
            ],
        ]);

        $this->assertFalse($result['ok']);
        $this->assertTrue(collect($result['errors'])->contains(
            fn (string $error): bool => str_contains($error, 'follower change window is not the last 7 days'),
        ));
    }

    public function test_deterministic_own_summary_uses_the_change_label(): void
    {
        $narrative = app(DailyBriefGenerator::class)->deterministicNarrative([
            'own' => [
                'handle' => 'letsgosocialuk',
                'followers_now' => 97,
                'posts_last_7d_count' => 2,
                'days_since_last_post' => 2,
                'followers_change_7d' => [
                    'change' => 4,
                    'from_date' => '2026-09-27',
                    'label' => '+4 since 27 September',
                ],
            ],
            'format_mix' => ['competitor_posts_24h' => 0, 'competitor_posts_7d' => 0, 'competitors_7d' => []],
            'best_times' => ['today_slot' => null, 'weekday_evening_block' => 'weekday evenings 19:00-21:00'],
            'competitors' => [],
        ]);

        $this->assertStringContainsString('+4 since 27 September', $narrative['own_summary']);
        $this->assertStringNotContainsString('gained 4 in the last 7 days', $narrative['own_summary']);
    }

    public function test_fallback_pluralises_a_single_post(): void
    {
        $narrative = app(DailyBriefGenerator::class)->deterministicNarrative([
            'own' => [
                'handle' => 'letsgosocialuk',
                'followers_now' => 97,
                'posts_last_7d_count' => 1,
                'days_since_last_post' => 1,
            ],
            'format_mix' => ['competitor_posts_24h' => 1, 'competitor_posts_7d' => 1, 'competitors_7d' => ['Reel' => 1]],
            'best_times' => ['today_slot' => null, 'weekday_evening_block' => 'weekday evenings 19:00-21:00'],
            'competitors' => [],
        ]);

        $this->assertStringContainsString('posted 1 time in the last 7 days', $narrative['own_summary']);
        $this->assertStringNotContainsString('posted 1 times', $narrative['own_summary']);
        $this->assertStringContainsString('Last post was 1 day ago', $narrative['own_summary']);
        $this->assertStringContainsString('published 1 post in the last 7 days, including 1 post in the last 24 hours', $narrative['competitor_summary']);
    }

    public function test_fallback_omits_below_usual_posts_as_hits_or_inspiration(): void
    {
        $facts = [
            'own' => [
                'handle' => 'letsgosocialuk',
                'followers_now' => 97,
                'posts_last_7d_count' => 1,
                'days_since_last_post' => 1,
            ],
            'format_mix' => ['competitor_posts_24h' => 1, 'competitor_posts_7d' => 1, 'competitors_7d' => ['Reel' => 1]],
            'best_times' => ['today_slot' => null, 'weekday_evening_block' => 'weekday evenings 19:00-21:00'],
            'competitors' => [],
            'top_competitor_hit_24h' => [
                'handle' => 'goodgym',
                'post_id' => 218,
                'times_usual' => 0.5,
                'format' => 'Reel',
                'hook' => 'Parkrun Saturday',
                'url' => 'https://www.instagram.com/p/abc/',
            ],
            'top_competitor_hit_7d' => [
                'handle' => 'goodgym',
                'post_id' => 218,
                'times_usual' => 0.5,
                'format' => 'Reel',
            ],
            'top_competitor_hit' => [
                'handle' => 'goodgym',
                'post_id' => 218,
                'times_usual' => 0.5,
                'format' => 'Reel',
            ],
        ];

        $generator = app(DailyBriefGenerator::class);
        $narrative = $generator->deterministicNarrative($facts);
        $payload = $generator->assemblePayload($facts, $narrative, [], true);

        $this->assertStringNotContainsString('Strongest recent post', $narrative['competitor_summary']);
        $this->assertStringNotContainsString('0.5', $narrative['actions'][0]['why']);
        $this->assertStringNotContainsString('goodgym', $narrative['actions'][0]['why']);
        $this->assertSame([], $narrative['actions'][0]['related_post_ids']);
        $this->assertNull($narrative['actions'][0]['hook']);
        $this->assertSame([], $payload['actions'][0]['inspiration']);
    }

    public function test_fallback_uses_a_post_that_beat_usual_as_inspiration(): void
    {
        $facts = [
            'own' => [
                'handle' => 'letsgosocialuk',
                'followers_now' => 97,
                'posts_last_7d_count' => 2,
                'days_since_last_post' => 2,
            ],
            'format_mix' => ['competitor_posts_24h' => 1, 'competitor_posts_7d' => 3, 'competitors_7d' => ['Reel' => 3]],
            'best_times' => ['today_slot' => null, 'weekday_evening_block' => 'weekday evenings 19:00-21:00'],
            'competitors' => [],
            'top_competitor_hit_24h' => [
                'handle' => 'goodgym',
                'post_id' => 99,
                'times_usual' => 2.4,
                'format' => 'Reel',
                'hook' => 'Parkrun Saturday',
                'url' => 'https://www.instagram.com/p/hit/',
            ],
        ];

        $generator = app(DailyBriefGenerator::class);
        $narrative = $generator->deterministicNarrative($facts);
        $payload = $generator->assemblePayload($facts, $narrative, [], true);

        $this->assertStringContainsString('Strongest recent post: @goodgym', $narrative['competitor_summary']);
        $this->assertSame([99], $narrative['actions'][0]['related_post_ids']);
        $this->assertSame('Parkrun Saturday', $narrative['actions'][0]['hook']);
        $this->assertSame(99, $payload['actions'][0]['inspiration'][0]['post_id'] ?? null);
    }

    public function test_validator_lists_unknown_handle_and_invented_number(): void
    {
        $facts = [
            'allowed_handles' => ['letsgosocialuk'],
            'allowed_post_ids' => [10],
            'own' => ['followers_now' => 98],
        ];

        $result = app(DailyBriefValidator::class)->validate([
            'headline' => '@ghostclub grew 999 followers',
            'actions' => [
                ['title' => 'One', 'why' => 'why', 'how' => 'how', 'related_handles' => ['ghostclub'], 'related_post_ids' => [11]],
                ['title' => 'Two', 'why' => 'why', 'how' => 'how', 'related_handles' => [], 'related_post_ids' => []],
                ['title' => 'Three', 'why' => 'why', 'how' => 'how', 'related_handles' => [], 'related_post_ids' => []],
            ],
            'own_summary' => 'ok',
            'competitor_summary' => 'ok',
            'watch' => [],
        ], $facts);

        $this->assertFalse($result['ok']);
        $this->assertContains('unknown handle @ghostclub', $result['errors']);
        $this->assertContains('unknown post id 11', $result['errors']);
        $this->assertTrue(collect($result['errors'])->contains(fn (string $error): bool => str_contains($error, 'invented number')));
    }

    public function test_email_is_not_sent_when_flag_is_off(): void
    {
        Mail::fake();
        $this->fakeValidLlm();

        $user = User::factory()->create([
            'daily_brief_enabled' => true,
            'daily_brief_email' => false,
        ]);
        BrandProfile::factory()->for($user)->create();
        $this->seedAccounts($user);

        app(DailyBriefGenerator::class)->generate($user);

        Mail::assertNothingOutgoing();
        Mail::assertNotQueued(DailyBriefMail::class);
    }

    public function test_artisan_json_prints_stored_brief(): void
    {
        $user = User::factory()->create(['daily_brief_enabled' => true]);
        $brief = DailyBrief::factory()->for($user)->create([
            'headline' => 'Post a Reel tonight.',
        ]);

        $this->artisan('snitch:daily-brief', [
            'user' => $user->id,
            '--json' => true,
        ])->expectsOutputToContain('Post a Reel tonight.')->assertSuccessful();

        $this->assertSame($brief->id, DailyBrief::query()->where('user_id', $user->id)->value('id'));
    }

    public function test_artisan_json_fails_when_missing(): void
    {
        $user = User::factory()->create();

        $this->artisan('snitch:daily-brief', [
            'user' => $user->id,
            '--json' => true,
        ])->assertFailed();
    }

    public function test_validator_allows_zero_and_one_as_counts(): void
    {
        $facts = [
            'allowed_handles' => ['letsgosocialuk', 'fuss.london'],
            'allowed_post_ids' => [],
            'own' => [
                'handle' => 'letsgosocialuk',
                'followers_now' => 97,
                'posts_last_7d_count' => 0,
                'posts_yesterday_count' => 0,
            ],
            'competitors' => [[
                'handle' => 'fuss.london',
                'posts_last_7d_count' => 1,
            ]],
        ];

        $result = app(DailyBriefValidator::class)->validate([
            'headline' => 'Post today',
            'actions' => [
                ['title' => 'One', 'why' => 'why', 'how' => 'how', 'related_handles' => [], 'related_post_ids' => []],
                ['title' => 'Two', 'why' => 'why', 'how' => 'how', 'related_handles' => [], 'related_post_ids' => []],
                ['title' => 'Three', 'why' => 'why', 'how' => 'how', 'related_handles' => [], 'related_post_ids' => []],
            ],
            'own_summary' => '@letsgosocialuk posted 0 times in the last 7 days and 0 since yesterday.',
            'competitor_summary' => '@fuss.london posted 1 time.',
            'watch' => [],
        ], $facts);

        $this->assertTrue($result['ok'], implode('; ', $result['errors']));
        $this->assertTrue(app(DailyBriefValidator::class)->isAllowedSmallInt('0'));
        $this->assertContains('0', app(DailyBriefValidator::class)->allowedNumbers($facts));
    }

    public function test_empty_accounts_are_excluded_from_longest_gap(): void
    {
        $facts = app(DailyBriefFactsBuilder::class);
        $user = User::factory()->create(['daily_brief_enabled' => true]);
        BrandProfile::factory()->for($user)->create();
        $own = TrackedAccount::factory()->for($user)->create([
            'platform' => Platform::Instagram,
            'handle' => 'letsgosocialuk',
            'is_own_account' => true,
        ]);
        $empty = TrackedAccount::factory()->for($user)->create([
            'platform' => Platform::Instagram,
            'handle' => 'fuss.london',
            'is_own_account' => false,
            'kind' => TrackedAccountKind::Competitor,
            'last_sync_status' => 'empty',
        ]);
        $quiet = TrackedAccount::factory()->for($user)->create([
            'platform' => Platform::Instagram,
            'handle' => 'goodgym',
            'is_own_account' => false,
            'kind' => TrackedAccountKind::Competitor,
        ]);

        Post::factory()->forAccount($own)->create([
            'posted_at' => CarbonImmutable::parse('2026-10-04 12:00:00', 'Europe/London'),
            'metrics' => ['likes' => 10, 'comments' => 0, 'views' => 0],
        ]);
        Post::factory()->forAccount($quiet)->create([
            'posted_at' => CarbonImmutable::parse('2026-09-20 12:00:00', 'Europe/London'),
            'metrics' => ['likes' => 10, 'comments' => 0, 'views' => 0],
        ]);

        $this->travelTo(CarbonImmutable::parse('2026-10-08 08:00:00', 'Europe/London'));
        $built = $facts->build($user, CarbonImmutable::parse('2026-10-08', 'Europe/London'));

        $this->assertSame('goodgym', $built['cadence_gaps']['longest_handle'] ?? null);
        $this->assertNotSame(999, $built['cadence_gaps']['longest_days'] ?? null);
        $this->assertNotSame('fuss.london', $built['cadence_gaps']['longest_handle'] ?? null);

        $allowed = app(DailyBriefValidator::class)->validate([
            'headline' => 'Plan for today',
            'actions' => [
                ['title' => 'One', 'why' => 'why', 'how' => 'how', 'related_handles' => [], 'related_post_ids' => []],
                ['title' => 'Two', 'why' => 'why', 'how' => 'how', 'related_handles' => [], 'related_post_ids' => []],
                ['title' => 'Three', 'why' => 'why', 'how' => 'how', 'related_handles' => [], 'related_post_ids' => []],
            ],
            'own_summary' => '@goodgym has the longest posting gap among competitors.',
            'competitor_summary' => 'ok',
            'watch' => [],
        ], $built);

        $this->assertTrue($allowed['ok'], implode('; ', $allowed['errors']));
        $this->assertNotNull($empty->id);
    }

    public function test_last_day_note_lists_all_competitor_posts_and_views_label_is_tidy(): void
    {
        $facts = [
            'own' => null,
            'format_mix' => ['competitor_posts_24h' => 3, 'competitors_7d' => []],
            'top_competitor_hit_24h' => [
                'handle' => 'great.friendship',
                'times_usual' => 2.2,
                'times_usual_label' => '2.2 times their usual',
                'format' => 'Reel',
            ],
            'competitors' => [
                [
                    'handle' => 'great.friendship',
                    'posts_last_24h' => [[
                        'format' => 'Reel',
                        'times_usual' => 2.2,
                        'times_usual_label' => '2.2 times their usual',
                    ]],
                ],
                [
                    'handle' => 'onehousesocialclub',
                    'posts_last_24h' => [[
                        'format' => 'Reel',
                        'times_usual' => 0.5,
                        'times_usual_label' => '0.5 times their usual',
                    ]],
                ],
                [
                    'handle' => 'goodgym',
                    'posts_last_24h' => [[
                        'format' => 'Reel',
                        'times_usual' => null,
                        'views_vs_usual' => 1.3,
                        'times_usual_label' => '1.3 times usual',
                    ]],
                ],
            ],
            'unused_weekly_ideas' => [],
            'best_times' => ['thin' => true, 'weekday_evening_block' => 'weekday evenings'],
            'ads_empty' => true,
            'brief_date' => '2026-10-08',
        ];

        $generator = app(DailyBriefGenerator::class);
        $payload = $generator->assemblePayload($facts, [
            'headline' => 'Plan',
            'actions' => [],
            'own_summary' => '',
            'competitor_summary' => '',
            'watch' => [],
        ], [], true);

        $tile = collect($payload['big_numbers'] ?? [])->firstWhere('label', 'Competitor posts in the last day');
        $note = (string) ($tile['note'] ?? '');

        $this->assertSame('3', $tile['value'] ?? null);
        $this->assertStringContainsString('@great.friendship Reel 2.2x', $note);
        $this->assertStringContainsString('@onehousesocialclub Reel 0.5x', $note);
        $this->assertStringContainsString('@goodgym Reel 1.3x', $note);
        $this->assertStringNotContainsString('views vs', $note);

        $narrative = $generator->deterministicNarrative([
            'own' => [
                'handle' => 'letsgosocialuk',
                'followers_now' => 97,
                'posts_last_7d_count' => 0,
                'days_since_last_post' => 2,
            ],
            'competitors' => $facts['competitors'],
            'format_mix' => [
                'competitor_posts_24h' => 3,
                'competitor_posts_7d' => 3,
                'competitors_7d' => ['Reel' => 3],
            ],
            'top_competitor_hit_24h' => [
                'handle' => 'goodgym',
                'post_id' => 99,
                'times_usual' => null,
                'views_vs_usual' => 1.3,
                'times_usual_label' => '1.3 times their usual views',
                'format' => 'Reel',
                'hook' => 'Parkrun Saturday',
            ],
            'best_times' => ['today_slot' => null, 'weekday_evening_block' => 'weekday evenings 19:00-21:00'],
        ]);

        $this->assertStringContainsString('1.3 times their usual views', $narrative['competitor_summary']);
        $this->assertStringNotContainsString('views vs', $narrative['competitor_summary']);
    }

    public function test_validator_accepts_in_window_times_usual_with_rounding_and_rewrites_out_of_window(): void
    {
        $facts = [
            'allowed_handles' => ['great.friendship', 'goodgym'],
            'allowed_post_ids' => [10, 11, 12],
            'brief_date' => '2026-10-10',
            'own' => ['handle' => 'letsgosocialuk', 'followers_now' => 97],
            'competitors' => [
                [
                    'handle' => 'great.friendship',
                    'posts_last_7d' => [[
                        'post_id' => 10,
                        'times_usual' => 2.25,
                        'format' => 'Reel',
                        'times_usual_label' => '2.3 times their usual',
                    ]],
                    'posts_last_24h' => [[
                        'post_id' => 10,
                        'times_usual' => 2.25,
                        'format' => 'Reel',
                    ]],
                    'best_post_7d' => ['post_id' => 10, 'times_usual' => 2.25],
                ],
                [
                    'handle' => 'goodgym',
                    'posts_last_7d' => [
                        [
                            'post_id' => 11,
                            'times_usual' => null,
                            'views_vs_usual' => 1.48,
                            'format' => 'Reel',
                            'times_usual_label' => '1.5 times their usual',
                        ],
                        [
                            'post_id' => 12,
                            'times_usual' => null,
                            'views_vs_usual' => 1.12,
                            'format' => 'Reel',
                            'times_usual_label' => '1.1 times their usual',
                        ],
                    ],
                    'best_post_7d' => ['post_id' => 11, 'views_vs_usual' => 1.48],
                ],
            ],
            'top_competitor_hit_7d' => [
                'handle' => 'great.friendship',
                'post_id' => 10,
                'times_usual' => 2.25,
            ],
            'top_competitor_hit' => [
                'handle' => 'great.friendship',
                'post_id' => 99,
                'times_usual' => 5.9,
            ],
        ];

        $actions = [
            ['title' => 'One', 'why' => 'why', 'how' => 'how', 'related_handles' => [], 'related_post_ids' => []],
            ['title' => 'Two', 'why' => 'why', 'how' => 'how', 'related_handles' => [], 'related_post_ids' => []],
            ['title' => 'Three', 'why' => 'why', 'how' => 'how', 'related_handles' => [], 'related_post_ids' => []],
        ];

        $allowed = app(DailyBriefValidator::class)->validate([
            'headline' => 'Plan for today',
            'actions' => $actions,
            'own_summary' => 'ok',
            'competitor_summary' => 'In the last 7 days @great.friendship hit 2.3x and @goodgym got 1.5 times their usual.',
            'watch' => [],
        ], $facts);

        $this->assertTrue($allowed['ok'], implode('; ', $allowed['errors']));

        // Rival figure attributed to the wrong handle is a validation error.
        $misattributed = app(DailyBriefValidator::class)->validate([
            'headline' => 'Plan for today',
            'actions' => $actions,
            'own_summary' => 'ok',
            'competitor_summary' => 'In the last 7 days @great.friendship best post was in the 1.1-1.5x range.',
            'watch' => [],
        ], $facts);

        $this->assertFalse($misattributed['ok']);
        $this->assertTrue(collect($misattributed['errors'])->contains(
            fn (string $error): bool => str_contains($error, 'times-usual figure does not match @great.friendship')
                || str_contains($error, 'week best-post figure is not from the last 7 days'),
        ));

        $rewritten = app(DailyBriefValidator::class)->validate([
            'headline' => 'Plan for today',
            'actions' => $actions,
            'own_summary' => 'ok',
            'competitor_summary' => 'In the last 7 days @great.friendship\'s best post got 5.9 times their usual.',
            'watch' => [],
        ], $facts);

        $this->assertTrue($rewritten['ok'], implode('; ', $rewritten['errors']));
        $this->assertStringContainsString('2.3', $rewritten['output']['competitor_summary']);
        $this->assertStringNotContainsString('5.9', $rewritten['output']['competitor_summary']);

        $viewsWording = app(DailyBriefValidator::class)->rewriteViewsVsUsualWordingInText(
            'goodgym posted a Reel at 1.3 views vs usual',
        );
        $this->assertSame('goodgym posted a Reel at 1.3 times their usual views', $viewsWording);
    }

    public function test_validator_rejects_cross_handle_multiplier_and_out_of_window_week_standout(): void
    {
        $facts = [
            'allowed_handles' => ['onehousesocialclub', 'great.friendship'],
            'allowed_post_ids' => [218, 248],
            'brief_date' => '2026-10-11',
            'own' => ['handle' => 'letsgosocialuk', 'followers_now' => 97],
            'competitors' => [
                [
                    'handle' => 'onehousesocialclub',
                    'posts_last_7d_count' => 0,
                    'posts_last_7d' => [],
                    'standout_winners' => [[
                        'post_id' => 218,
                        'handle' => 'onehousesocialclub',
                        'times_usual' => 5.0,
                        'format' => 'Reel',
                        'hook' => 'Living Room Listens',
                        'window_days' => 14,
                        'times_usual_label' => '5.0 times their usual',
                    ]],
                ],
                [
                    'handle' => 'great.friendship',
                    'posts_last_7d' => [[
                        'post_id' => 248,
                        'handle' => 'great.friendship',
                        'times_usual' => 2.5,
                        'format' => 'Reel',
                        'times_usual_label' => '2.5 times their usual',
                    ]],
                    'best_post_7d' => [
                        'post_id' => 248,
                        'handle' => 'great.friendship',
                        'times_usual' => 2.5,
                    ],
                ],
            ],
            'top_competitor_hit_7d' => [
                'post_id' => 248,
                'handle' => 'great.friendship',
                'times_usual' => 2.5,
            ],
            'top_competitor_hit' => [
                'post_id' => 218,
                'handle' => 'onehousesocialclub',
                'times_usual' => 5.0,
            ],
        ];

        $actions = [
            [
                'title' => 'Study the Living Room Listens Reel',
                'why' => '@onehousesocialclub hit 5.0 times their usual.',
                'how' => 'Borrow the hook.',
                'related_handles' => ['onehousesocialclub'],
                'related_post_ids' => [218],
            ],
            ['title' => 'Two', 'why' => 'why', 'how' => 'how', 'related_handles' => [], 'related_post_ids' => []],
            ['title' => 'Three', 'why' => 'why', 'how' => 'how', 'related_handles' => [], 'related_post_ids' => []],
        ];

        $badLine = '@onehousesocialclub\'s Living Room Listens Reel had 2.5 times their usual engagement.';
        $this->assertSame(
            'times-usual figure does not match @onehousesocialclub posts',
            app(DailyBriefValidator::class)->unsupportedHandleTimesUsualClaim($badLine, $facts),
        );

        $wrongMultiplier = app(DailyBriefValidator::class)->validate([
            'headline' => 'Plan for today',
            'actions' => $actions,
            'own_summary' => 'ok',
            'competitor_summary' => $badLine,
            'watch' => [],
            'trends' => [$badLine],
        ], $facts);

        // Rewrite swaps the borrowed 2.5x to this handle's 5.0x instead of discarding the brief.
        $this->assertTrue($wrongMultiplier['ok'], implode('; ', $wrongMultiplier['errors']));
        $this->assertStringContainsString('5.0', $wrongMultiplier['output']['competitor_summary']);
        $this->assertStringNotContainsString('2.5', $wrongMultiplier['output']['competitor_summary']);

        $weekStandout = app(DailyBriefValidator::class)->validate([
            'headline' => 'Plan for today',
            'actions' => $actions,
            'own_summary' => 'ok',
            'competitor_summary' => '@onehousesocialclub posted 5 times this week and had a standout Living Room Listens Reel at 5.0 times their usual.',
            'watch' => [],
        ], $facts);

        // Softens "this week" when the standout is outside the 7-day window, and keeps the correct 5.0x.
        $this->assertTrue($weekStandout['ok'], implode('; ', $weekStandout['errors']));
        $this->assertStringContainsString('recently', $weekStandout['output']['competitor_summary']);
        $this->assertStringNotContainsString('this week', $weekStandout['output']['competitor_summary']);
        $this->assertStringContainsString('5.0', $weekStandout['output']['competitor_summary']);

        $correct = app(DailyBriefValidator::class)->validate([
            'headline' => 'Plan for today',
            'actions' => $actions,
            'own_summary' => 'ok',
            'competitor_summary' => '@great.friendship hit 2.5x this week. @onehousesocialclub\'s Living Room Listens Reel recently got 5.0 times their usual.',
            'watch' => [],
        ], $facts);

        $this->assertTrue($correct['ok'], implode('; ', $correct['errors']));
    }

    public function test_validator_catches_display_name_multiplier_misattribution(): void
    {
        $facts = [
            'allowed_handles' => ['onehousesocialclub', 'great.friendship', 'goodgym'],
            'allowed_post_ids' => [260, 248, 300],
            'brief_date' => '2026-10-13',
            'own' => ['handle' => 'letsgosocialuk', 'followers_now' => 97],
            'competitors' => [
                [
                    'handle' => 'onehousesocialclub',
                    'display_name' => 'One House Social Club',
                    'posts_last_7d' => [
                        [
                            'post_id' => 260,
                            'handle' => 'onehousesocialclub',
                            'times_usual' => 23.5,
                            'format' => 'Carousel',
                            'hook' => 'presentation night',
                            'times_usual_label' => '23.5 times their usual',
                        ],
                        [
                            'post_id' => 261,
                            'handle' => 'onehousesocialclub',
                            'times_usual' => 0.5,
                            'format' => 'Reel',
                        ],
                        [
                            'post_id' => 262,
                            'handle' => 'onehousesocialclub',
                            'times_usual' => 0.7,
                            'format' => 'Image',
                        ],
                    ],
                    'best_post_7d' => [
                        'post_id' => 260,
                        'handle' => 'onehousesocialclub',
                        'times_usual' => 23.5,
                    ],
                ],
                [
                    'handle' => 'great.friendship',
                    'display_name' => 'Great Friendship',
                    'posts_last_7d' => [[
                        'post_id' => 248,
                        'handle' => 'great.friendship',
                        'times_usual' => 2.5,
                        'format' => 'Reel',
                        'hook' => 'UNICEF',
                        'times_usual_label' => '2.5 times their usual',
                    ]],
                    'best_post_7d' => [
                        'post_id' => 248,
                        'handle' => 'great.friendship',
                        'times_usual' => 2.5,
                    ],
                ],
                [
                    'handle' => 'goodgym',
                    'display_name' => 'GoodGym',
                    'posts_last_7d' => [[
                        'post_id' => 300,
                        'handle' => 'goodgym',
                        'times_usual' => null,
                        'views_vs_usual' => 1.7,
                        'format' => 'Reel',
                        'times_usual_label' => '1.7 times their usual',
                    ]],
                    'best_post_7d' => [
                        'post_id' => 300,
                        'handle' => 'goodgym',
                        'views_vs_usual' => 1.7,
                    ],
                ],
            ],
        ];

        $badSummary = 'One House Social Club hit 1.7 times their usual engagement and Great Friendship\'s UNICEF Reel got 1.7 times their usual views.';

        $rawError = app(DailyBriefValidator::class)->unsupportedHandleTimesUsualClaim($badSummary, $facts);
        $this->assertSame('times-usual figure does not match @onehousesocialclub posts', $rawError);

        $actions = [
            ['title' => 'One', 'why' => 'why', 'how' => 'how', 'related_handles' => [], 'related_post_ids' => []],
            ['title' => 'Two', 'why' => 'why', 'how' => 'how', 'related_handles' => [], 'related_post_ids' => []],
            ['title' => 'Three', 'why' => 'why', 'how' => 'how', 'related_handles' => [], 'related_post_ids' => []],
        ];

        $result = app(DailyBriefValidator::class)->validate([
            'headline' => 'One House presentation-night Carousel at 23.5x usual',
            'actions' => $actions,
            'own_summary' => 'ok',
            'competitor_summary' => $badSummary,
            'watch' => [],
            'trends' => [$badSummary],
        ], $facts);

        $this->assertTrue($result['ok'], implode('; ', $result['errors']));
        $this->assertStringContainsString('23.5', $result['output']['competitor_summary']);
        $this->assertStringContainsString('2.5', $result['output']['competitor_summary']);
        $this->assertStringNotContainsString('1.7', $result['output']['competitor_summary']);
        $this->assertStringNotContainsString('1.7', $result['output']['trends'][0] ?? '');
        $this->assertStringContainsString('23.5x', $result['output']['headline']);
    }

    public function test_validator_repairs_oct20_misbound_claims(): void
    {
        $facts = [
            'allowed_handles' => ['onehousesocialclub', 'goodgym'],
            'allowed_post_ids' => [401, 402, 501, 502],
            'brief_date' => '2026-10-20',
            'own' => ['handle' => 'letsgosocialuk', 'followers_now' => 103],
            'competitors' => [
                [
                    'handle' => 'onehousesocialclub',
                    'display_name' => 'One House Social Club',
                    'posts_last_7d_count' => 4,
                    'posts_last_7d' => [
                        [
                            'post_id' => 401,
                            'handle' => 'onehousesocialclub',
                            'format' => 'Reel',
                            'times_usual' => 1.7,
                            'times_usual_label' => '1.7 times their usual',
                            'hook' => 'Mansion party',
                            'caption' => 'Mansion party on 1 Nov - tickets open',
                            'posted_at' => '2026-10-18T12:00:00+01:00',
                            'posted_label' => 'Sat 18 Oct, 12:00',
                        ],
                        [
                            'post_id' => 402,
                            'handle' => 'onehousesocialclub',
                            'format' => 'Carousel',
                            'times_usual' => 0.9,
                            'times_usual_label' => '0.9 times their usual',
                            'hook' => 'Weekend faces',
                            'posted_at' => '2026-10-19T12:00:00+01:00',
                            'posted_label' => 'Sun 19 Oct, 12:00',
                        ],
                    ],
                    'best_post_7d' => [
                        'post_id' => 401,
                        'handle' => 'onehousesocialclub',
                        'format' => 'Reel',
                        'times_usual' => 1.7,
                    ],
                ],
                [
                    'handle' => 'goodgym',
                    'display_name' => 'GoodGym',
                    'posts_last_7d_count' => 6,
                    'posts_last_7d' => [
                        [
                            'post_id' => 501,
                            'handle' => 'goodgym',
                            'format' => 'Reel',
                            'times_usual' => 2.3,
                            'times_usual_label' => '2.3 times their usual',
                            'hook' => 'Tuesday session',
                            'posted_at' => '2026-10-17T09:00:00+01:00',
                            'posted_label' => 'Fri 17 Oct, 09:00',
                        ],
                        [
                            'post_id' => 502,
                            'handle' => 'goodgym',
                            'format' => 'Carousel',
                            'times_usual' => 0.8,
                            'times_usual_label' => '0.8 times their usual',
                            'hook' => 'Sunlight Society collab',
                            'caption' => 'Sunlight Society collab day',
                            'posted_at' => '2026-10-08T12:00:00+01:00',
                            'posted_label' => 'Wed 8 Oct, 12:00',
                        ],
                    ],
                    'best_post_7d' => [
                        'post_id' => 501,
                        'handle' => 'goodgym',
                        'format' => 'Reel',
                        'times_usual' => 2.3,
                    ],
                ],
            ],
        ];

        $actions = [
            [
                'title' => 'Engage with One House',
                'why' => 'They are active this week.',
                'how' => 'Reply to someone else\'s comment on their mansion Reel.',
                'related_handles' => ['onehousesocialclub'],
                'related_post_ids' => [401],
            ],
            ['title' => 'Two', 'why' => 'why', 'how' => 'how', 'related_handles' => [], 'related_post_ids' => []],
            ['title' => 'Three', 'why' => 'why', 'how' => 'how', 'related_handles' => [], 'related_post_ids' => []],
        ];

        $result = app(DailyBriefValidator::class)->validate([
            'headline' => 'Plan for today',
            'actions' => $actions,
            'own_summary' => 'ok',
            'competitor_summary' => 'One House Social Club leads with 4 posts this week and a standout Carousel at 1.7 times their usual. Their mansion Reel posted on 1 Nov. GoodGym\'s Sunlight Society collab hit 2.3 times their usual as a Reel.',
            'watch' => [],
        ], $facts);

        $this->assertTrue($result['ok'], implode('; ', $result['errors']));
        $summary = (string) ($result['output']['competitor_summary'] ?? '');
        $this->assertStringContainsString('GoodGym leads with 6 posts', $summary);
        $this->assertStringNotContainsString('leads with 4 posts', $summary);
        $this->assertStringContainsString('Reel', $summary);
        $this->assertStringNotContainsString('Carousel at 1.7', $summary);
        $this->assertStringNotContainsString('posted on 1 Nov', $summary);
        $this->assertStringNotContainsString('Sunlight Society collab hit 2.3', $summary);
        $how = (string) ($result['output']['actions'][0]['how'] ?? '');
        $this->assertMatchesRegularExpression('/\bcomment on\b/i', $how);
        $this->assertStringNotContainsString('someone else\'s comment', $how);
        $this->assertDoesNotMatchRegularExpression('/\breply\b/i', $how);

        $noBaseline = app(DailyBriefValidator::class)->validate([
            'headline' => 'Plan for today',
            'actions' => [
                ['title' => 'One', 'why' => 'why', 'how' => 'how', 'related_handles' => [], 'related_post_ids' => []],
                ['title' => 'Two', 'why' => 'why', 'how' => 'how', 'related_handles' => [], 'related_post_ids' => []],
                ['title' => 'Three', 'why' => 'why', 'how' => 'how', 'related_handles' => [], 'related_post_ids' => []],
            ],
            'own_summary' => 'ok',
            'competitor_summary' => '@quietclub hit 3.0 times their usual this week.',
            'watch' => [],
        ], [
            'allowed_handles' => ['quietclub'],
            'allowed_post_ids' => [900],
            'brief_date' => '2026-10-20',
            'own' => ['handle' => 'letsgosocialuk'],
            'competitors' => [[
                'handle' => 'quietclub',
                'posts_last_7d_count' => 1,
                'posts_last_7d' => [[
                    'post_id' => 900,
                    'handle' => 'quietclub',
                    'format' => 'Reel',
                    'times_usual' => null,
                    'views_vs_usual' => null,
                    'times_usual_label' => 'not enough history',
                    'likes_hidden' => true,
                ]],
            ]],
        ]);

        $this->assertTrue($noBaseline['ok'], implode('; ', $noBaseline['errors']));
        $this->assertStringNotContainsString('3.0 times their usual', (string) ($noBaseline['output']['competitor_summary'] ?? ''));
    }

    public function test_template_only_ops_flag_overwrites_ready_llm_brief(): void
    {
        Sleep::fake();
        $this->fakeValidLlm();

        $user = User::factory()->create(['daily_brief_enabled' => true]);
        BrandProfile::factory()->for($user)->create();
        $this->seedAccounts($user);

        $generator = app(DailyBriefGenerator::class);
        $llmBrief = $generator->generate($user);
        $this->assertFalse($llmBrief->payload['validation']['used_fallback'] ?? true);

        Http::fake([
            'https://nano-gpt.test/api/v1/chat/completions' => Http::response('should not be called', 500),
        ]);

        $this->artisan('snitch:generate-daily-briefs', [
            '--user' => $user->id,
            '--fallback' => true,
        ])->assertSuccessful();

        $llmBrief->refresh();
        $this->assertTrue($llmBrief->payload['validation']['used_fallback'] ?? false);
        $this->assertSame(0, (int) $llmBrief->llm_attempts);
        Http::assertNothingSent();
    }

    public function test_fallback_template_binds_format_date_and_multiplier_from_one_post(): void
    {
        $reel = [
            'post_id' => 701,
            'url' => 'https://www.instagram.com/reel/DeukSzHikVM/',
            'handle' => 'onehousesocialclub',
            'format' => 'Reel',
            'times_usual' => 1.2,
            'times_usual_label' => '1.2 times their usual',
            'comments' => 0,
            'hook' => 'Tuesday night',
            'posted_at' => '2026-10-20T20:19:00+01:00',
            'posted_label' => 'Tue 20 Oct, 20:19',
        ];
        $carousel = [
            'post_id' => 702,
            'url' => 'https://www.instagram.com/p/carousel12oct/',
            'handle' => 'onehousesocialclub',
            'format' => 'Carousel',
            'times_usual' => 28.9,
            'times_usual_label' => '28.9 times their usual',
            'comments' => 12,
            'hook' => 'Presentation night',
            'posted_at' => '2026-10-12T18:00:00+01:00',
            'posted_label' => 'Sun 12 Oct, 18:00',
        ];
        $oldReel = [
            'post_id' => 703,
            'handle' => 'onehousesocialclub',
            'format' => 'Reel',
            'times_usual' => 0.8,
            'times_usual_label' => '0.8 times their usual',
            'comments' => 0,
            'hook' => 'Quiet midweek',
            'posted_at' => '2026-10-15T12:00:00+01:00',
            'posted_label' => 'Wed 15 Oct, 12:00',
        ];
        $goodgym = [
            'post_id' => 801,
            'handle' => 'goodgym',
            'format' => 'Reel',
            'times_usual' => 1.1,
            'times_usual_label' => '1.1 times their usual',
            'comments' => 0,
            'hook' => 'Tuesday session',
            'posted_at' => '2026-10-20T09:00:00+01:00',
            'posted_label' => 'Tue 20 Oct, 09:00',
        ];

        $facts = [
            'allowed_handles' => ['onehousesocialclub', 'goodgym', 'letsgosocialuk'],
            'allowed_post_ids' => [701, 702, 703, 801],
            'brief_date' => '2026-10-21',
            'own' => [
                'handle' => 'letsgosocialuk',
                'followers_now' => 104,
                'posts_last_7d_count' => 1,
                'days_since_last_post' => 2,
            ],
            'format_mix' => [
                'competitor_posts_24h' => 2,
                'competitor_posts_7d' => 5,
                'competitors_7d' => ['Reel' => 4, 'Carousel' => 1],
            ],
            'best_times' => [
                'today_slot' => ['hour' => 20, 'label' => '20:00'],
                'weekday_evening_block' => 'weekday evenings 19:00-21:00',
            ],
            'competitors' => [
                [
                    'handle' => 'onehousesocialclub',
                    'display_name' => 'One House Social Club',
                    'posts_last_7d_count' => 3,
                    'posts_last_7d' => [$reel, $carousel, $oldReel],
                    'posts_last_24h' => [$reel],
                    'best_post_7d' => $carousel,
                ],
                [
                    'handle' => 'goodgym',
                    'display_name' => 'GoodGym',
                    'posts_last_7d_count' => 2,
                    'posts_last_7d' => [$goodgym],
                    'posts_last_24h' => [$goodgym],
                    'best_post_7d' => $goodgym,
                ],
            ],
            'top_competitor_hit_24h' => $reel,
            'top_competitor_hit_7d' => $carousel,
        ];

        $generator = app(DailyBriefGenerator::class);
        $narrative = $generator->checkedNarrative($generator->deterministicNarrative($facts), $facts);

        $headline = (string) ($narrative['headline'] ?? '');
        $summary = (string) ($narrative['competitor_summary'] ?? '');
        $blob = strtolower($headline.' '.$summary.' '.json_encode($narrative['actions'] ?? []));

        $this->assertStringContainsString('Reel', $headline);
        $this->assertStringContainsString('20 Oct', $headline);
        $this->assertStringContainsString('1.2', $headline);
        $this->assertStringNotContainsString('Carousel', $headline);
        $this->assertStringContainsString('Reel from 20 Oct', $summary);
        $this->assertStringContainsString('1.2 times their usual', $summary);
        $this->assertStringNotContainsString('from 12 Oct', $summary);
        $this->assertStringNotContainsString('from 12 October', $summary);
        $this->assertDoesNotMatchRegularExpression('/from 12 oct(?:ober)?[^.]{0,40}0\\.8/i', $summary);
        $this->assertDoesNotMatchRegularExpression('/reply(?:ing)?\\s+to\\s+(?:the\\s+|their\\s+)?comments?/i', $blob);

        $misbound = app(DailyBriefValidator::class)->validate([
            'headline' => 'One House Carousel at 1.2x usual',
            'actions' => [
                [
                    'title' => 'Engage GoodGym',
                    'why' => 'Their Reel is active.',
                    'how' => 'Reply to comments on their Reel.',
                    'related_handles' => ['goodgym'],
                    'related_post_ids' => [801],
                ],
                ['title' => 'Two', 'why' => 'why', 'how' => 'how', 'related_handles' => [], 'related_post_ids' => []],
                ['title' => 'Three', 'why' => 'why', 'how' => 'how', 'related_handles' => [], 'related_post_ids' => []],
            ],
            'own_summary' => 'ok',
            'competitor_summary' => 'One House Reel from 12 October at 0.8 times their usual.',
            'watch' => [],
        ], $facts);

        $fixedSummary = (string) ($misbound['output']['competitor_summary'] ?? '');
        $fixedHeadline = (string) ($misbound['output']['headline'] ?? '');
        $fixedHow = (string) ($misbound['output']['actions'][0]['how'] ?? '');

        $this->assertStringNotContainsString('Carousel at 1.2', $fixedHeadline);
        $this->assertStringNotContainsString('from 12 October at 0.8', $fixedSummary);
        $this->assertDoesNotMatchRegularExpression('/reply(?:ing)?\\s+to\\s+(?:the\\s+|their\\s+)?comments?/i', $fixedHow);
        $this->assertMatchesRegularExpression('/comment on the post/i', $fixedHow);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function llmResponse(array $payload): array
    {
        return [
            'choices' => [[
                'message' => [
                    'content' => json_encode($payload),
                ],
            ]],
        ];
    }

    private function fakeValidLlm(): void
    {
        Http::fake([
            'https://nano-gpt.test/api/v1/chat/completions' => Http::response($this->llmResponse([
                'headline' => 'Post a Reel today at 20:00.',
                'actions' => [
                    [
                        'title' => 'Post a Reel today at 20:00.',
                        'why' => '@onehousesocialclub is posting Reels.',
                        'how' => 'Keep it short and ask people to comment.',
                        'when' => '20:00',
                        'format' => 'Reel',
                        'hook' => 'POV you found a free social',
                        'related_handles' => ['onehousesocialclub'],
                        'related_post_ids' => [],
                    ],
                    [
                        'title' => 'Reply to comments on your last post.',
                        'why' => 'You posted 2 times this week.',
                        'how' => 'Reply and share the post to your Story.',
                        'when' => null,
                        'format' => 'Engage',
                        'hook' => null,
                        'related_handles' => ['letsgosocialuk'],
                        'related_post_ids' => [],
                    ],
                    [
                        'title' => 'Leave a comment on @goodgym.',
                        'why' => 'They posted 1 Reel this week.',
                        'how' => 'Leave a genuine comment and DM about a joint session.',
                        'when' => null,
                        'format' => 'Engage',
                        'hook' => null,
                        'related_handles' => ['goodgym'],
                        'related_post_ids' => [],
                    ],
                ],
                'own_summary' => 'You posted 2 times this week.',
                'competitor_summary' => '@onehousesocialclub posted Reels this week.',
                'watch' => ['Daily tracking starts today'],
            ])),
        ]);
    }

    private function seedAccounts(User $user): void
    {
        $own = TrackedAccount::factory()->for($user)->create([
            'platform' => Platform::Instagram,
            'handle' => 'letsgosocialuk',
            'is_own_account' => true,
            'kind' => TrackedAccountKind::Competitor,
            'followers' => 98,
        ]);
        TrackedAccount::factory()->for($user)->create([
            'platform' => Platform::Instagram,
            'handle' => 'onehousesocialclub',
            'is_own_account' => false,
            'kind' => TrackedAccountKind::Competitor,
            'followers' => 5287,
        ]);
        TrackedAccount::factory()->for($user)->create([
            'platform' => Platform::Instagram,
            'handle' => 'goodgym',
            'is_own_account' => false,
            'kind' => TrackedAccountKind::Competitor,
            'followers' => 28768,
            'last_sync_status' => 'success',
        ]);

        FollowerSnapshot::factory()->create([
            'social_account_id' => $own->social_account_id,
            'followers' => 93,
            'captured_on' => now('Europe/London')->subDays(7)->toDateString(),
        ]);
        FollowerSnapshot::factory()->create([
            'social_account_id' => $own->social_account_id,
            'followers' => 98,
            'captured_on' => now('Europe/London')->toDateString(),
        ]);

        Post::factory()->forAccount($own)->create([
            'posted_at' => now('Europe/London')->subDays(2),
            'caption' => 'First meetup? Here is what happens.',
            'metrics' => ['likes' => 13, 'comments' => 1, 'views' => 0],
        ]);
        Post::factory()->forAccount($own)->create([
            'posted_at' => now('Europe/London')->subDays(4),
            'caption' => 'LGS 4 over and out',
            'metrics' => ['likes' => 21, 'comments' => 0, 'views' => 0],
        ]);
    }
}
