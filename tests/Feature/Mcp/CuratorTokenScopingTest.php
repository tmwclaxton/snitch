<?php

namespace Tests\Feature\Mcp;

use App\Enums\Platform;
use App\Enums\TrackedAccountKind;
use App\Mcp\Servers\SnitchServer;
use App\Mcp\Support\McpAuth;
use App\Mcp\Tools\GetAccountSummaryTool;
use App\Mcp\Tools\ListTrackersTool;
use App\Mcp\Tools\ListUsersTool;
use App\Mcp\Tools\SetPanelsTool;
use App\Models\DashboardPanel;
use App\Models\DashboardPanelVersion;
use App\Models\TrackedAccount;
use App\Models\User;
use App\Services\Billing\UsageBillingService;
use App\Services\Dashboard\Panels\DashboardPanelService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

class CuratorTokenScopingTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_token_cannot_read_another_users_data_via_user_id(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        app(UsageBillingService::class)->creditClaimBonus($owner);
        app(UsageBillingService::class)->creditClaimBonus($other);

        TrackedAccount::factory()->for($other)->create([
            'platform' => Platform::Instagram,
            'handle' => 'secretclub',
            'kind' => TrackedAccountKind::Competitor,
        ]);

        $plain = $owner->createSanctumToken('mcp')->plainTextToken;
        $token = PersonalAccessToken::findToken($plain);
        $this->assertNotNull($token);
        $owner->withSanctumToken($token);
        $this->actingAs($owner);

        SnitchServer::tool(ListTrackersTool::class, [
            'user_id' => $other->id,
        ])->assertHasErrors();
    }

    public function test_curator_token_can_read_another_users_data(): void
    {
        $actor = User::factory()->create(['is_admin' => true]);
        $other = User::factory()->create();
        app(UsageBillingService::class)->creditClaimBonus($other);

        TrackedAccount::factory()->for($other)->create([
            'platform' => Platform::Instagram,
            'handle' => 'watchedclub',
            'followers' => 2200,
            'is_own_account' => true,
            'kind' => TrackedAccountKind::Competitor,
        ]);

        $plain = $actor->createSanctumToken(McpAuth::CURATOR_TOKEN_NAME, [McpAuth::CURATOR_ABILITY])->plainTextToken;
        $token = PersonalAccessToken::findToken($plain);
        $this->assertNotNull($token);
        $actor->withSanctumToken($token);
        $this->actingAs($actor);

        SnitchServer::tool(ListTrackersTool::class, [
            'user_id' => $other->id,
        ])
            ->assertOk()
            ->assertSee('watchedclub');

        SnitchServer::tool(GetAccountSummaryTool::class, [
            'user_id' => $other->id,
            'handle' => 'watchedclub',
        ])
            ->assertOk()
            ->assertSee('2200');
    }

    public function test_user_token_cannot_call_list_users(): void
    {
        $user = User::factory()->create();
        $plain = $user->createSanctumToken('mcp')->plainTextToken;
        $token = PersonalAccessToken::findToken($plain);
        $this->assertNotNull($token);
        $user->withSanctumToken($token);
        $this->actingAs($user);

        SnitchServer::tool(ListUsersTool::class)->assertHasErrors();
    }

    public function test_set_panels_validation_rejects_bad_types(): void
    {
        $user = User::factory()->create();
        $service = app(DashboardPanelService::class);

        $this->expectException(ValidationException::class);

        $service->setForUser($user, [
            [
                'type' => 'not_real',
                'title' => 'Bad',
                'payload' => [],
            ],
        ]);
    }

    public function test_curator_set_panels_persists_and_versions_for_rollback(): void
    {
        $actor = User::factory()->create(['is_admin' => true]);
        $subject = User::factory()->create();

        DashboardPanel::factory()->for($subject)->create([
            'type' => DashboardPanel::TYPE_NOTE,
            'title' => 'Old',
            'payload' => ['body' => 'Old note body.'],
            'source' => 'curator',
        ]);

        $plain = $actor->createSanctumToken(McpAuth::CURATOR_TOKEN_NAME, [McpAuth::CURATOR_ABILITY])->plainTextToken;
        $token = PersonalAccessToken::findToken($plain);
        $this->assertNotNull($token);
        $actor->withSanctumToken($token);
        $this->actingAs($actor);

        SnitchServer::tool(SetPanelsTool::class, [
            'user_id' => $subject->id,
            'panels' => [
                [
                    'type' => DashboardPanel::TYPE_BIG_NUMBER,
                    'title' => 'Followers',
                    'payload' => [
                        'label' => 'Followers',
                        'value' => '9,999',
                    ],
                ],
            ],
        ])->assertOk()->assertSee('9,999');

        $this->assertDatabaseHas('dashboard_panels', [
            'user_id' => $subject->id,
            'type' => DashboardPanel::TYPE_BIG_NUMBER,
        ]);
        $this->assertSame(1, DashboardPanelVersion::query()->where('user_id', $subject->id)->count());

        $service = app(DashboardPanelService::class);
        $service->rollbackForUser($subject, 1, $actor);

        $this->assertDatabaseHas('dashboard_panels', [
            'user_id' => $subject->id,
            'type' => DashboardPanel::TYPE_NOTE,
        ]);
    }

    public function test_admin_can_revoke_curator_token(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $plain = $admin->createSanctumToken(McpAuth::CURATOR_TOKEN_NAME, [McpAuth::CURATOR_ABILITY])->plainTextToken;
        $token = PersonalAccessToken::findToken($plain);
        $this->assertNotNull($token);

        $this->actingAs($admin)
            ->delete(route('admin.curator-tokens.destroy', $token->id))
            ->assertRedirect(route('admin.curator-tokens.index'));

        $this->assertFalse(
            $admin->sanctumTokens()->where('name', McpAuth::CURATOR_TOKEN_NAME)->exists(),
        );
    }
}
