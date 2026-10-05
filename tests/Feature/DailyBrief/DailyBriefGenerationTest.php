<?php

namespace Tests\Feature\DailyBrief;

use App\Enums\Platform;
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
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
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
                ],
                [
                    'handle' => 'sobersocial_',
                    'posts_last_7d' => 4,
                    'days_since_last_post' => 1,
                    'distinct_days_posted_last_7' => 4,
                    'posted_every_day_last_7' => false,
                ],
            ],
        ];

        $result = app(DailyBriefValidator::class)->validate([
            'headline' => 'Plan for today',
            'actions' => [
                ['title' => 'One', 'why' => 'why', 'how' => 'how', 'related_handles' => [], 'related_post_ids' => []],
                ['title' => 'Two', 'why' => 'why', 'how' => 'how', 'related_handles' => [], 'related_post_ids' => []],
                ['title' => 'Three', 'why' => 'why', 'how' => 'how', 'related_handles' => [], 'related_post_ids' => []],
            ],
            'own_summary' => 'ok',
            'competitor_summary' => 'Goodgym posts daily but hides likes',
            'watch' => ['sobersocial_ posts daily'],
        ], $facts);

        $this->assertFalse($result['ok']);
        $this->assertContains('unsupported daily claim for @goodgym', $result['errors']);
        $this->assertContains('unsupported daily claim for @sobersocial_', $result['errors']);

        $facts['cadence'][0]['posted_every_day_last_7'] = true;
        $ok = app(DailyBriefValidator::class)->validate([
            'headline' => 'Plan for today',
            'actions' => [
                ['title' => 'One', 'why' => 'why', 'how' => 'how', 'related_handles' => [], 'related_post_ids' => []],
                ['title' => 'Two', 'why' => 'why', 'how' => 'how', 'related_handles' => [], 'related_post_ids' => []],
                ['title' => 'Three', 'why' => 'why', 'how' => 'how', 'related_handles' => [], 'related_post_ids' => []],
            ],
            'own_summary' => 'ok',
            'competitor_summary' => 'Goodgym posts daily but hides likes',
            'watch' => ['Keep an eye on @sobersocial_'],
        ], $facts);

        $this->assertTrue($ok['ok'], implode('; ', $ok['errors']));
    }

    public function test_validator_rejects_daily_claims_then_retries_and_falls_back(): void
    {
        $user = User::factory()->create(['daily_brief_enabled' => true]);
        BrandProfile::factory()->for($user)->create();
        $this->seedAccounts($user);

        Http::fake([
            'https://nano-gpt.test/api/v1/chat/completions' => Http::sequence()
                ->push($this->llmResponse([
                    'headline' => 'Plan for today',
                    'actions' => [
                        ['title' => 'One', 'why' => 'why', 'how' => 'how', 'related_handles' => [], 'related_post_ids' => []],
                        ['title' => 'Two', 'why' => 'why', 'how' => 'how', 'related_handles' => [], 'related_post_ids' => []],
                        ['title' => 'Three', 'why' => 'why', 'how' => 'how', 'related_handles' => [], 'related_post_ids' => []],
                    ],
                    'own_summary' => 'ok',
                    'competitor_summary' => 'Goodgym posts daily but hides likes',
                    'watch' => ['ok'],
                ]))
                ->push($this->llmResponse([
                    'headline' => 'Plan for today',
                    'actions' => [
                        ['title' => 'One', 'why' => 'why', 'how' => 'how', 'related_handles' => [], 'related_post_ids' => []],
                        ['title' => 'Two', 'why' => 'why', 'how' => 'how', 'related_handles' => [], 'related_post_ids' => []],
                        ['title' => 'Three', 'why' => 'why', 'how' => 'how', 'related_handles' => [], 'related_post_ids' => []],
                    ],
                    'own_summary' => 'ok',
                    'competitor_summary' => 'Goodgym still posts every day',
                    'watch' => ['ok'],
                ])),
        ]);

        $brief = app(DailyBriefGenerator::class)->generate($user);

        $this->assertSame(2, $brief->llm_attempts);
        $this->assertTrue($brief->payload['validation']['used_fallback'] ?? false);
        $this->assertStringNotContainsString('posts daily', (string) ($brief->payload['competitor_summary'] ?? ''));
        $this->assertStringNotContainsString('every day', (string) ($brief->payload['competitor_summary'] ?? ''));
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

        $result = app(DailyBriefValidator::class)->validate([
            'headline' => 'Plan for today',
            'actions' => [
                ['title' => 'One', 'why' => 'why', 'how' => 'how', 'related_handles' => [], 'related_post_ids' => []],
                ['title' => 'Two', 'why' => 'why', 'how' => 'how', 'related_handles' => [], 'related_post_ids' => []],
                ['title' => 'Three', 'why' => 'why', 'how' => 'how', 'related_handles' => [], 'related_post_ids' => []],
            ],
            'own_summary' => 'ok',
            'competitor_summary' => 'ok',
            'watch' => ['@sobersocial_ posts almost daily'],
        ], $facts);

        $this->assertFalse($result['ok']);
        $this->assertContains('unsupported almost-daily claim for @sobersocial_', $result['errors']);

        $facts['cadence'][0]['distinct_days_posted_last_7'] = 6;
        $facts['cadence'][0]['posted_almost_daily_last_7'] = true;
        $ok = app(DailyBriefValidator::class)->validate([
            'headline' => 'Plan for today',
            'actions' => [
                ['title' => 'One', 'why' => 'why', 'how' => 'how', 'related_handles' => [], 'related_post_ids' => []],
                ['title' => 'Two', 'why' => 'why', 'how' => 'how', 'related_handles' => [], 'related_post_ids' => []],
                ['title' => 'Three', 'why' => 'why', 'how' => 'how', 'related_handles' => [], 'related_post_ids' => []],
            ],
            'own_summary' => 'ok',
            'competitor_summary' => 'ok',
            'watch' => ['@sobersocial_ posts almost daily'],
        ], $facts);

        $this->assertTrue($ok['ok'], implode('; ', $ok['errors']));

        $daily = app(DailyBriefValidator::class)->validate([
            'headline' => 'Plan for today',
            'actions' => [
                ['title' => 'One', 'why' => 'why', 'how' => 'how', 'related_handles' => [], 'related_post_ids' => []],
                ['title' => 'Two', 'why' => 'why', 'how' => 'how', 'related_handles' => [], 'related_post_ids' => []],
                ['title' => 'Three', 'why' => 'why', 'how' => 'how', 'related_handles' => [], 'related_post_ids' => []],
            ],
            'own_summary' => 'ok',
            'competitor_summary' => 'ok',
            'watch' => ['@sobersocial_ posts daily'],
        ], $facts);

        $this->assertFalse($daily['ok']);
        $this->assertContains('unsupported daily claim for @sobersocial_', $daily['errors']);
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
