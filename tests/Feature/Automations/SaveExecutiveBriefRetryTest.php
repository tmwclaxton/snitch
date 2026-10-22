<?php

namespace Tests\Feature\Automations;

use App\Enums\Platform;
use App\Enums\TrackedAccountKind;
use App\Models\AutomationRun;
use App\Models\DailyBrief;
use App\Models\TrackedAccount;
use App\Models\User;
use App\Services\Automations\AutomationRunTokenIssuer;
use App\Services\Automations\SaveExecutiveBriefService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SaveExecutiveBriefRetryTest extends TestCase
{
    use RefreshDatabase;

    public function test_validation_failure_keeps_run_open_for_retry(): void
    {
        $user = User::factory()->create();
        TrackedAccount::factory()->for($user)->create([
            'platform' => Platform::Instagram,
            'handle' => 'retryclub',
            'kind' => TrackedAccountKind::Competitor,
        ]);

        DailyBrief::factory()->for($user)->create([
            'status' => 'ready',
            'brief_date' => now('Europe/London')->toDateString(),
            'headline' => 'Existing headline.',
            'payload' => [
                'headline' => 'Existing headline.',
                'summary' => 'Existing summary.',
                'actions' => [],
            ],
            'facts' => [
                'own' => [
                    'handle' => 'retryclub',
                    'followers_now' => 100,
                    'posts_last_7d_count' => 0,
                    'days_since_last_post' => 5,
                    'posts_last_7d_by_format' => [],
                ],
                'competitors' => [],
                'format_mix' => [
                    'competitor_posts_7d' => 0,
                    'competitors_7d' => [],
                ],
            ],
        ]);

        $issued = app(AutomationRunTokenIssuer::class)->issue($user, AutomationRun::TYPE_BRIEF);
        $run = $issued['run'];

        $result = app(SaveExecutiveBriefService::class)->save($user, $run, [
            'headline' => 'Invented 9999x spike with no data.',
            'summary' => 'Rivals hit 9999x with zero grounding.',
            'suggestions' => [
                ['title' => 'Post something', 'why' => 'Because 9999x', 'evidence_post_ids' => [999999]],
                ['title' => 'Post again', 'why' => 'Because 8888x', 'evidence_post_ids' => [999998]],
                ['title' => 'Post thrice', 'why' => 'Because 7777x', 'evidence_post_ids' => [999997]],
            ],
        ]);

        $this->assertFalse($result['ok']);
        $this->assertNotEmpty($result['errors']);
        $fresh = $run->fresh();
        $this->assertNotNull($fresh);
        $this->assertSame(AutomationRun::STATUS_RUNNING, $fresh->status);
        $this->assertNotNull($fresh->personal_access_token_id);
        $this->assertIsArray($fresh->result_meta['validation_errors'] ?? null);
    }
}
