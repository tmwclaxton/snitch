<?php

namespace Tests\Feature\Mcp;

use App\Enums\Platform;
use App\Enums\TrackedAccountKind;
use App\Mcp\Servers\SnitchServer;
use App\Mcp\Tools\GetAccountSummaryTool;
use App\Mcp\Tools\GetCompetitorPostsTool;
use App\Mcp\Tools\GetGapsTool;
use App\Mcp\Tools\ListTrackersTool;
use App\Models\FollowerSnapshot;
use App\Models\Post;
use App\Models\TrackedAccount;
use App\Models\User;
use App\Services\Billing\UsageBillingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class McpReadToolsScopingTest extends TestCase
{
    use RefreshDatabase;

    public function test_list_trackers_only_returns_the_authenticated_users_accounts(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        app(UsageBillingService::class)->creditClaimBonus($owner);
        app(UsageBillingService::class)->creditClaimBonus($other);

        $own = TrackedAccount::factory()->for($owner)->create([
            'platform' => Platform::Instagram,
            'handle' => 'ownerclub',
            'is_own_account' => true,
            'kind' => TrackedAccountKind::Competitor,
        ]);
        TrackedAccount::factory()->for($owner)->create([
            'platform' => Platform::Instagram,
            'handle' => 'rivalclub',
            'is_own_account' => false,
            'kind' => TrackedAccountKind::Competitor,
        ]);
        TrackedAccount::factory()->for($other)->create([
            'platform' => Platform::Instagram,
            'handle' => 'secretclub',
            'is_own_account' => false,
            'kind' => TrackedAccountKind::Competitor,
        ]);

        $this->actingAs($owner);

        SnitchServer::tool(ListTrackersTool::class)
            ->assertOk()
            ->assertSee('ownerclub')
            ->assertSee('rivalclub')
            ->assertDontSee('secretclub');

        $this->assertSame($owner->id, $own->user_id);
    }

    public function test_get_account_summary_cannot_read_another_users_handle(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        app(UsageBillingService::class)->creditClaimBonus($owner);
        app(UsageBillingService::class)->creditClaimBonus($other);

        $foreign = TrackedAccount::factory()->for($other)->create([
            'platform' => Platform::Instagram,
            'handle' => 'foreignclub',
            'followers' => 5000,
            'is_own_account' => true,
            'kind' => TrackedAccountKind::Competitor,
        ]);
        Post::factory()->forAccount($foreign)->create([
            'posted_at' => now()->subDay(),
            'caption' => 'Secret caption',
        ]);
        FollowerSnapshot::factory()->create([
            'social_account_id' => $foreign->social_account_id,
            'followers' => 4800,
            'captured_on' => now()->subDays(7)->toDateString(),
        ]);

        $this->actingAs($owner);

        SnitchServer::tool(GetAccountSummaryTool::class, [
            'handle' => 'foreignclub',
        ])->assertHasErrors();
    }

    public function test_get_competitor_posts_is_scoped_to_token_user(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        app(UsageBillingService::class)->creditClaimBonus($owner);
        app(UsageBillingService::class)->creditClaimBonus($other);

        $ownerRival = TrackedAccount::factory()->for($owner)->create([
            'platform' => Platform::Instagram,
            'handle' => 'ownerrival',
            'is_own_account' => false,
            'kind' => TrackedAccountKind::Competitor,
        ]);
        $otherRival = TrackedAccount::factory()->for($other)->create([
            'platform' => Platform::Instagram,
            'handle' => 'otherrival',
            'is_own_account' => false,
            'kind' => TrackedAccountKind::Competitor,
        ]);

        Post::factory()->forAccount($ownerRival)->create([
            'posted_at' => now()->subHours(3),
            'caption' => 'Owner visible post',
            'metrics' => ['likes' => 40, 'comments' => 2, 'views' => 800],
        ]);
        Post::factory()->forAccount($otherRival)->create([
            'posted_at' => now()->subHours(2),
            'caption' => 'Other secret post',
            'metrics' => ['likes' => 90, 'comments' => 9, 'views' => 9000],
        ]);

        $this->actingAs($owner);

        SnitchServer::tool(GetCompetitorPostsTool::class, ['limit' => 20])
            ->assertOk()
            ->assertSee('Owner visible post')
            ->assertDontSee('Other secret post')
            ->assertDontSee('otherrival');
    }

    public function test_get_gaps_is_scoped_to_token_user(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        app(UsageBillingService::class)->creditClaimBonus($owner);
        app(UsageBillingService::class)->creditClaimBonus($other);

        TrackedAccount::factory()->for($owner)->create([
            'platform' => Platform::Instagram,
            'handle' => 'ownerquiet',
            'is_own_account' => false,
            'kind' => TrackedAccountKind::Competitor,
        ]);
        TrackedAccount::factory()->for($other)->create([
            'platform' => Platform::Instagram,
            'handle' => 'otherquiet',
            'is_own_account' => false,
            'kind' => TrackedAccountKind::Competitor,
        ]);

        $this->actingAs($owner);

        SnitchServer::tool(GetGapsTool::class)
            ->assertOk()
            ->assertSee('ownerquiet')
            ->assertDontSee('otherquiet');
    }

    public function test_settings_can_revoke_mcp_token(): void
    {
        $user = User::factory()->create();
        $user->createSanctumToken('mcp');

        $this->actingAs($user)
            ->delete(route('settings.mcp.token.revoke'))
            ->assertRedirect(route('settings.mcp.show'));

        $this->assertFalse($user->sanctumTokens()->where('name', 'mcp')->exists());
    }
}
