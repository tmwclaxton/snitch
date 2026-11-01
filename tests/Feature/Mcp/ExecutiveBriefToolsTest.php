<?php

namespace Tests\Feature\Mcp;

use App\Mcp\Servers\SnitchServer;
use App\Mcp\Support\McpAuth;
use App\Mcp\Tools\GetExecutiveBriefTool;
use App\Mcp\Tools\SetExecutiveBriefTool;
use App\Models\BrandProfile;
use App\Models\DailyBrief;
use App\Models\TrackedAccount;
use App\Models\User;
use App\Services\Billing\UsageBillingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

class ExecutiveBriefToolsTest extends TestCase
{
    use RefreshDatabase;

    public function test_get_executive_brief_returns_memo_for_ready_brief(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        SnitchServer::tool(GetExecutiveBriefTool::class)
            ->assertHasErrors();
    }

    public function test_curator_can_override_summary_and_suggestions(): void
    {
        $actor = User::factory()->create(['is_admin' => true]);
        $subject = User::factory()->create();
        BrandProfile::factory()->for($subject)->create();
        app(UsageBillingService::class)->creditClaimBonus($subject);
        TrackedAccount::factory()->for($subject)->create(['handle' => 'danclub']);

        DailyBrief::query()->create([
            'user_id' => $subject->id,
            'brief_date' => now('Europe/London')->toDateString(),
            'status' => 'ready',
            'headline' => 'Old headline.',
            'payload' => [
                'summary' => 'Generated summary.',
                'actions' => [
                    [
                        'title' => 'Old action.',
                        'why' => 'Old why.',
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
                    'handle' => 'danclub',
                    'followers_now' => 800,
                    'posts_last_7d_count' => 0,
                    'posts_last_7d_by_format' => [],
                    'posts_last_30d_by_format' => [],
                    'followers_change_7d' => ['label' => 'flat', 'change' => 0],
                    'days_since_last_post' => 9,
                ],
            ],
            'model' => 'fallback',
            'llm_attempts' => 0,
            'was_free' => true,
            'generated_at' => now(),
        ]);

        $plain = $actor->createSanctumToken(McpAuth::CURATOR_TOKEN_NAME, [McpAuth::CURATOR_ABILITY])->plainTextToken;
        $token = PersonalAccessToken::findToken($plain);
        $this->assertNotNull($token);
        $actor->withSanctumToken($token);
        $this->actingAs($actor);

        SnitchServer::tool(SetExecutiveBriefTool::class, [
            'user_id' => $subject->id,
            'summary' => 'Curated standing: quiet week, rivals still posting Reels.',
            'suggestions' => [
                [
                    'title' => 'Ship one Reel tonight.',
                    'why' => 'You have 0 posts in 7 days while rivals posted Reels; n is in the brief facts.',
                ],
            ],
        ])->assertHasErrors();

        $brief = DailyBrief::query()->where('user_id', $subject->id)->first();
        $this->assertNotNull($brief);
        $this->assertArrayNotHasKey('curator_overrides', $brief->payload);
    }

    public function test_user_token_cannot_set_executive_brief(): void
    {
        $user = User::factory()->create();
        app(UsageBillingService::class)->creditClaimBonus($user);
        $plain = $user->createSanctumToken('mcp')->plainTextToken;
        $token = PersonalAccessToken::findToken($plain);
        $this->assertNotNull($token);
        $user->withSanctumToken($token);
        $this->actingAs($user);

        SnitchServer::tool(SetExecutiveBriefTool::class, [
            'user_id' => $user->id,
            'summary' => 'Nope.',
        ])->assertHasErrors();
    }
}
