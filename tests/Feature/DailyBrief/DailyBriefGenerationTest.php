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
use App\Services\Brief\DailyBriefGenerator;
use App\Services\Brief\DailyBriefValidator;
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
