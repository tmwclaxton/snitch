<?php

namespace Tests\Feature\Brief;

use App\Enums\Platform;
use App\Models\BrandProfile;
use App\Models\DailyBrief;
use App\Models\TrackedAccount;
use App\Models\User;
use App\Services\Brief\ExecutiveBriefPresenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AgentNarrativePresenterTest extends TestCase
{
    use RefreshDatabase;

    public function test_presenter_hides_fallback_summary_without_agent_narrative(): void
    {
        $user = User::factory()->create();
        BrandProfile::factory()->for($user)->create();
        TrackedAccount::factory()->for($user)->create([
            'platform' => Platform::Instagram,
            'handle' => 'ownclub',
            'is_own_account' => true,
        ]);

        DailyBrief::query()->create([
            'user_id' => $user->id,
            'brief_date' => now('Europe/London')->toDateString(),
            'status' => 'ready',
            'headline' => '21 days quiet while rivals posted 13 times.',
            'payload' => [
                'headline' => '21 days quiet while rivals posted 13 times.',
                'own_summary' => '@ownclub has been quiet for 21 days while rivals published 13 posts this week.',
                'competitor_summary' => 'Competitors published 13 posts in the last 7 days.',
                'actions' => [
                    [
                        'title' => 'Post a Reel today.',
                        'why' => 'Break the quiet streak.',
                        'related_post_ids' => [],
                    ],
                ],
                'own_last_7_days' => [],
                'competitor_moves' => [],
                'data_freshness' => [],
            ],
            'facts' => [
                'own' => [
                    'handle' => 'ownclub',
                    'followers_now' => 104,
                    'posts_last_7d_count' => 0,
                    'days_since_last_post' => 21,
                ],
                'format_mix' => ['competitor_posts_7d' => 13],
            ],
            'model' => 'fallback',
            'llm_attempts' => 0,
            'was_free' => true,
            'generated_at' => now(),
        ]);

        $memo = app(ExecutiveBriefPresenter::class)->forUser($user);

        $this->assertNull($memo['narrative']);
        $this->assertTrue($memo['awaiting_narrative']);
        $this->assertStringContainsString('still drafting', (string) $memo['narrative_note']);
        $this->assertSame([], $memo['own_activity']);
        $this->assertSame([], $memo['competitor_activity']);
        $this->assertNull($memo['headline_detail']);
        $this->assertNotEmpty($memo['suggestions']);
    }

    public function test_presenter_reuses_yesterdays_agent_narrative(): void
    {
        $user = User::factory()->create();
        BrandProfile::factory()->for($user)->create();
        TrackedAccount::factory()->for($user)->create([
            'platform' => Platform::Instagram,
            'handle' => 'ownclub',
            'is_own_account' => true,
        ]);

        $agentNarrative = implode(' ', array_fill(0, 90, 'word'));

        DailyBrief::query()->create([
            'user_id' => $user->id,
            'brief_date' => now('Europe/London')->subDay()->toDateString(),
            'status' => 'ready',
            'headline' => 'Come back with a Reel this week.',
            'payload' => [
                'headline' => 'Come back with a Reel this week.',
                'narrative' => $agentNarrative,
                'automation_overrides' => [
                    'updated_at' => now()->subDay()->toIso8601String(),
                    'run_uuid' => 'prior-run',
                    'sections' => [],
                ],
                'own_summary' => 'Prior own summary.',
                'competitor_summary' => 'Prior competitor summary.',
                'actions' => [
                    [
                        'title' => 'Post a Reel tonight.',
                        'why' => 'Prior agent why grounded in a hit.',
                        'related_post_ids' => [],
                    ],
                ],
                'own_last_7_days' => [],
                'competitor_moves' => [],
                'data_freshness' => [],
            ],
            'facts' => [
                'own' => [
                    'handle' => 'ownclub',
                    'followers_now' => 100,
                    'posts_last_7d_count' => 0,
                    'days_since_last_post' => 20,
                ],
            ],
            'model' => 'automation',
            'llm_attempts' => 1,
            'was_free' => true,
            'generated_at' => now()->subDay(),
        ]);

        DailyBrief::query()->create([
            'user_id' => $user->id,
            'brief_date' => now('Europe/London')->toDateString(),
            'status' => 'ready',
            'headline' => 'Deterministic quiet headline.',
            'payload' => [
                'headline' => 'Deterministic quiet headline.',
                'own_summary' => '@ownclub quiet fact dump.',
                'competitor_summary' => 'Competitors published 5 posts.',
                'actions' => [
                    [
                        'title' => 'Deterministic action.',
                        'why' => 'Deterministic why.',
                        'related_post_ids' => [],
                    ],
                ],
                'own_last_7_days' => [],
                'competitor_moves' => [],
                'data_freshness' => [],
            ],
            'facts' => [
                'own' => [
                    'handle' => 'ownclub',
                    'followers_now' => 104,
                    'posts_last_7d_count' => 0,
                    'days_since_last_post' => 21,
                ],
            ],
            'model' => 'fallback',
            'llm_attempts' => 0,
            'was_free' => true,
            'generated_at' => now(),
        ]);

        $memo = app(ExecutiveBriefPresenter::class)->forUser($user);

        $this->assertFalse($memo['awaiting_narrative']);
        $this->assertSame($agentNarrative, $memo['narrative']);
        $this->assertSame('Come back with a Reel this week.', $memo['headline']);
        $this->assertStringContainsString('Prior agent why', $memo['suggestions'][0]['why']);
    }
}
