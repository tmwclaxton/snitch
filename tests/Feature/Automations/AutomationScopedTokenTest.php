<?php

namespace Tests\Feature\Automations;

use App\Enums\Platform;
use App\Enums\TrackedAccountKind;
use App\Mcp\Servers\SnitchServer;
use App\Mcp\Tools\GetDailyBriefTool;
use App\Mcp\Tools\ListTrackersTool;
use App\Mcp\Tools\PostChatReplyTool;
use App\Mcp\Tools\SaveExecutiveBriefTool;
use App\Models\AutomationCallAudit;
use App\Models\AutomationRun;
use App\Models\DailyBrief;
use App\Models\TrackedAccount;
use App\Models\User;
use App\Services\Automations\AutomationRunTokenIssuer;
use App\Services\Billing\UsageBillingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

class AutomationScopedTokenTest extends TestCase
{
    use RefreshDatabase;

    public function test_automation_token_for_user_a_cannot_read_user_b_even_with_user_id_arg(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();
        app(UsageBillingService::class)->creditClaimBonus($userA);
        app(UsageBillingService::class)->creditClaimBonus($userB);

        TrackedAccount::factory()->for($userA)->create([
            'platform' => Platform::Instagram,
            'handle' => 'aliceclub',
            'kind' => TrackedAccountKind::Competitor,
            'is_own_account' => true,
        ]);
        TrackedAccount::factory()->for($userB)->create([
            'platform' => Platform::Instagram,
            'handle' => 'secretbob',
            'kind' => TrackedAccountKind::Competitor,
            'is_own_account' => true,
        ]);

        DailyBrief::factory()->for($userA)->create([
            'headline' => 'Alice brief headline.',
            'status' => 'ready',
        ]);
        DailyBrief::factory()->for($userB)->create([
            'headline' => 'Bob secret brief.',
            'status' => 'ready',
        ]);

        $issued = app(AutomationRunTokenIssuer::class)->issue($userA, AutomationRun::TYPE_BRIEF);
        $plain = $issued['plain_text_token'];
        $token = PersonalAccessToken::findToken($plain);
        $this->assertNotNull($token);
        $userA->withSanctumToken($token);
        $this->actingAs($userA);

        SnitchServer::tool(ListTrackersTool::class, [
            'user_id' => $userB->id,
        ])
            ->assertOk()
            ->assertSee('aliceclub')
            ->assertDontSee('secretbob');

        SnitchServer::tool(GetDailyBriefTool::class, [
            'user_id' => $userB->id,
        ])
            ->assertOk()
            ->assertSee('Alice brief headline.')
            ->assertDontSee('Bob secret brief.');

        // HTTP path: Bearer automation token + crafted user_id must still stay on A.
        $this->withToken($plain)
            ->postJson('/mcp', [
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'tools/call',
                'params' => [
                    'name' => 'list_trackers',
                    'arguments' => ['user_id' => $userB->id],
                ],
            ])
            ->assertOk()
            ->assertJsonMissing(['secretbob']);

        $this->assertDatabaseHas('automation_call_audits', [
            'automation_run_id' => $issued['run']->id,
            'user_id' => $userA->id,
            'tool' => 'list_trackers',
        ]);
        $this->assertSame(1, AutomationCallAudit::query()->where('user_id', $userA->id)->count());
    }

    public function test_save_executive_brief_requires_brief_write_ability(): void
    {
        $user = User::factory()->create();
        app(UsageBillingService::class)->creditClaimBonus($user);
        TrackedAccount::factory()->for($user)->create([
            'platform' => Platform::Instagram,
            'handle' => 'chatonly',
        ]);

        $issued = app(AutomationRunTokenIssuer::class)->issue($user, AutomationRun::TYPE_CHAT);
        $token = PersonalAccessToken::findToken($issued['plain_text_token']);
        $this->assertNotNull($token);
        $user->withSanctumToken($token);
        $this->actingAs($user);

        SnitchServer::tool(SaveExecutiveBriefTool::class, [
            'headline' => 'Nope.',
            'summary' => 'Nope.',
            'suggestions' => [
                ['title' => 'One', 'why' => 'Why one'],
                ['title' => 'Two', 'why' => 'Why two'],
                ['title' => 'Three', 'why' => 'Why three'],
            ],
        ])->assertHasErrors();
    }

    public function test_post_chat_reply_finalises_and_revokes_token(): void
    {
        $user = User::factory()->create();
        $issued = app(AutomationRunTokenIssuer::class)->issue($user, AutomationRun::TYPE_CHAT, [
            'mode' => 'in_app_chat',
        ]);
        $plain = $issued['plain_text_token'];
        $token = PersonalAccessToken::findToken($plain);
        $this->assertNotNull($token);
        $user->withSanctumToken($token);
        $this->actingAs($user);

        SnitchServer::tool(PostChatReplyTool::class, [
            'text' => 'Partial draft',
            'partial' => true,
        ])->assertOk()->assertSee('Partial draft');

        SnitchServer::tool(PostChatReplyTool::class, [
            'text' => 'Final answer from automation.',
            'done' => true,
        ])->assertOk()->assertSee('Final answer from automation.');

        $run = $issued['run']->fresh();
        $this->assertSame(AutomationRun::STATUS_SUCCEEDED, $run?->status);
        $this->assertNull($run?->personal_access_token_id);
        $this->assertNull(PersonalAccessToken::findToken($plain));
    }
}
