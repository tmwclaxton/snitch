<?php

namespace Tests\Feature\Billing;

use App\Enums\BillingVendor;
use App\Enums\PostType;
use App\Exceptions\InsufficientCreditsException;
use App\Exceptions\PlatformSubscriptionRequiredException;
use App\Http\Middleware\EnsureMcpProductAccess;
use App\Mcp\Servers\SnitchServer;
use App\Mcp\Support\McpAuth;
use App\Mcp\Tools\BillingStatusTool;
use App\Mcp\Tools\CreateCreditCheckoutTool;
use App\Mcp\Tools\ListCompetitorsTool;
use App\Mcp\Tools\ListFeedTool;
use App\Models\BrandProfile;
use App\Models\Post;
use App\Models\TrackedAccount;
use App\Models\User;
use App\Services\Billing\UsageBillingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Cashier\Subscription;
use Tests\TestCase;

class BillingPaywallTest extends TestCase
{
    use RefreshDatabase;

    private UsageBillingService $billing;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'billing.platform_stripe_price' => 'price_platform_test',
            'billing.claim_bonus_pence' => 500,
            'billing.subscription_bonus_pence' => 3000,
            'billing.min_run_balance_pence' => 20,
            'billing.topup_expiry_months' => 3,
            'billing.credit_packs.pack_10.stripe_price' => 'price_credits_10_test',
            'billing.price_multiplier' => 1.3,
            'billing.usd_to_gbp' => 1.0,
        ]);

        $this->billing = app(UsageBillingService::class);
    }

    public function test_starter_credit_allows_access_without_paid_plan(): void
    {
        $user = User::factory()->create();
        $this->billing->creditClaimBonus($user);

        $this->assertTrue($this->billing->canAccessProduct($user));
        $this->assertFalse($this->billing->paywallState($user)['blocked']);
        $this->assertFalse($this->billing->paywallState($user)['can_top_up']);
    }

    public function test_blocked_after_starter_credit_exhausted_without_plan(): void
    {
        $user = User::factory()->create();
        $this->billing->creditClaimBonus($user);

        while ($this->billing->claimBonusRemainingPence($user) > 20) {
            $this->billing->charge($user, 'explore.search', BillingVendor::Snitch);
        }

        $this->expectException(PlatformSubscriptionRequiredException::class);
        $this->billing->assertCanAccessProduct($user);
    }

    public function test_top_up_alone_does_not_restore_access_after_starter(): void
    {
        $user = User::factory()->create();
        $this->billing->creditClaimBonus($user);

        while ($this->billing->canAccessProduct($user)) {
            $this->billing->charge($user, 'explore.search', BillingVendor::Snitch);
        }

        $this->billing->creditFromTopUp($user, 1000, 'topup:bypass-attempt');

        $this->assertFalse($this->billing->canAccessProduct($user));
        $this->assertSame('subscribe', $this->billing->paywallState($user)['reason']);
    }

    public function test_subscribed_user_with_balance_can_access(): void
    {
        $user = User::factory()->withoutStarterCredit()->create();
        $this->subscribe($user);
        $this->billing->creditSubscriptionBonus($user, 'subscription_bonus:invoice:in_access');

        $this->assertTrue($this->billing->canAccessProduct($user));
        $this->assertTrue($this->billing->paywallState($user)['can_top_up']);
    }

    public function test_subscribed_user_blocked_when_monthly_allowance_spent(): void
    {
        $user = User::factory()->withoutStarterCredit()->create();
        $this->subscribe($user);
        $this->billing->creditFromTopUp($user, 21, 'topup:thin');

        $this->billing->charge($user, 'explore.search', BillingVendor::Snitch);

        while ($this->billing->balancePence($user) > 20) {
            $this->billing->charge($user, 'explore.view', BillingVendor::Snitch);
        }

        $this->expectException(InsufficientCreditsException::class);
        $this->billing->assertCanAccessProduct($user);
    }

    public function test_claim_bonus_never_expires(): void
    {
        $user = User::factory()->withoutStarterCredit()->create();
        $entry = $this->billing->creditClaimBonus($user);

        $this->assertNotNull($entry);
        $this->assertNull($entry->expires_at);
        $this->assertSame(500.0, (float) $entry->remaining_pence);

        $this->travel(400)->days();

        $this->assertSame(500.0, $this->billing->balancePence($user));
        $this->assertFalse($this->billing->canAccessProduct($user));

        $this->subscribe($user);

        $this->assertTrue($this->billing->canAccessProduct($user->fresh()));
    }

    public function test_subscription_bonus_expires_at_month_end(): void
    {
        $this->travelTo(now()->startOfMonth()->addDays(2)->setTime(12, 0));

        $user = User::factory()->withoutStarterCredit()->create();
        $this->subscribe($user);
        $entry = $this->billing->creditSubscriptionBonus($user, 'subscription_bonus:invoice:in_month');

        $this->assertNotNull($entry);
        $this->assertTrue($entry->expires_at?->isSameDay(now()->endOfMonth()));
        $this->assertSame(3000.0, $this->billing->balancePence($user));

        $this->travelTo(now()->endOfMonth()->addSecond());

        $this->assertSame(0.0, $this->billing->balancePence($user));
        $this->assertFalse($this->billing->canAccessProduct($user));
    }

    public function test_top_up_credits_expire_after_three_months(): void
    {
        $user = User::factory()->withoutStarterCredit()->create();
        $this->subscribe($user);
        $entry = $this->billing->creditFromTopUp($user, 1000, 'topup:expiry');

        $this->assertNotNull($entry->expires_at);
        $this->assertTrue($entry->expires_at->greaterThan(now()->addMonthsNoOverflow(2)));
        $this->assertSame(1000.0, $this->billing->balancePence($user));

        $this->travel(3)->months();
        $this->travel(1)->day();

        $this->assertSame(0.0, $this->billing->balancePence($user));
    }

    public function test_credit_checkout_blocked_without_paid_plan(): void
    {
        $user = User::factory()->create();
        BrandProfile::factory()->for($user)->create();

        $this->actingAs($user)
            ->post(route('billing.checkout'), [
                'product' => 'credits',
                'pack' => 'pack_10',
            ])
            ->assertRedirect(route('billing.edit'));
    }

    public function test_dashboard_paywall_props_when_starter_exhausted(): void
    {
        $user = $this->paywalledUser();
        $account = TrackedAccount::factory()->competitor()->for($user)->create(['handle' => 'secret-rival']);
        Post::factory()->forAccount($account)->create([
            'type' => PostType::Reel,
            'caption' => 'secret paywalled caption',
        ]);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('subscription.can_run_billable', false)
                ->where('subscription.paywall.blocked', true)
                ->where('subscription.paywall.reason', 'subscribe')
                ->where('subscription.paywall.can_top_up', false)
                ->where('subscription.competitors_used', 0)
                ->where('stats.tracked_accounts', 0)
                ->where('stats.posts', 0)
                ->where('stats.winners', 0)
                ->where('recent_posts', [])
                ->where('top_winners', [])
                ->where('activity.heatmap', [])
                ->missing('recent_posts.0')
            );
    }

    public function test_product_pages_omit_data_when_paywalled(): void
    {
        $user = $this->paywalledUser();
        $account = TrackedAccount::factory()->competitor()->for($user)->create(['handle' => 'leaky-handle']);
        Post::factory()->forAccount($account)->create([
            'type' => PostType::Reel,
            'caption' => 'must-not-appear-in-inertia',
        ]);

        $this->actingAs($user)
            ->get(route('feed.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('subscription.paywall.blocked', true)
                ->where('subscription.can_run_billable', false)
                ->where('accounts', [])
                ->where('posts.data', [])
                ->where('posts.total', 0)
            );

        $this->actingAs($user)
            ->get(route('competitors.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('competitors/Index')
                ->where('subscription.paywall.blocked', true)
                ->where('accounts', [])
                ->where('suggestions', [])
                ->where('competitorBrief', '')
                ->where('suggestRun', null)
            );

        $this->actingAs($user)
            ->get(route('explore.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('subscription.paywall.blocked', true)
                ->where('posts.data', [])
                ->where('posts.total', 0)
                ->where('terms.hook_type', [])
            );

        $this->actingAs($user)
            ->get(route('winners.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('subscription.paywall.blocked', true)
                ->where('winners', [])
                ->where('rescoreRun', null)
            );

        $this->actingAs($user)
            ->get(route('influencers.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('subscription.paywall.blocked', true)
                ->where('suggestions', [])
                ->where('reviewQueue', [])
                ->where('keptAccounts', [])
            );

        $this->actingAs($user)
            ->get(route('backlog.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('subscription.paywall.blocked', true)
                ->where('posts.data', [])
                ->where('counts.queue', 0)
            );

        $this->actingAs($user)
            ->get(route('competitors.show', $account))
            ->assertRedirect(route('competitors.index'));
    }

    public function test_subscribed_user_still_receives_product_data(): void
    {
        $user = User::factory()->withoutStarterCredit()->create();
        BrandProfile::factory()->for($user)->create();
        $this->subscribe($user);
        $this->billing->creditSubscriptionBonus($user, 'subscription_bonus:invoice:data');

        $account = TrackedAccount::factory()->competitor()->for($user)->create(['handle' => 'visible-rival']);
        Post::factory()->forAccount($account)->create([
            'type' => PostType::Reel,
            'caption' => 'visible caption',
        ]);

        $this->actingAs($user)
            ->get(route('competitors.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('subscription.paywall.blocked', false)
                ->where('subscription.competitors_used', 1)
            );

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('subscription.paywall.blocked', false)
                ->where('stats.tracked_accounts', 1)
                ->where('stats.posts', 1)
            );

        $this->actingAs($user)
            ->get(route('competitors.show', $account))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('competitors/Show')
                ->where('account.handle', 'visible-rival')
            );
    }

    public function test_json_status_endpoints_return_402_when_paywalled(): void
    {
        $user = $this->paywalledUser();

        $this->actingAs($user)
            ->getJson(route('competitors.suggest.status', ['suggestId' => '00000000-0000-4000-8000-000000000001']))
            ->assertStatus(402)
            ->assertJsonPath('paywall.blocked', true)
            ->assertJsonMissingPath('suggestions');

        $this->actingAs($user)
            ->getJson(route('winners.rescore.status', ['runId' => '00000000-0000-4000-8000-000000000002']))
            ->assertStatus(402)
            ->assertJsonPath('paywall.blocked', true);
    }

    public function test_billing_page_still_reachable_when_paywalled(): void
    {
        $user = $this->paywalledUser();

        $this->actingAs($user)
            ->get(route('billing.edit'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('billing/Index')
                ->where('subscription.paywall.blocked', true)
                ->where('subscription.paywall.can_top_up', false)
            );
    }

    public function test_product_mutation_redirects_when_paywalled(): void
    {
        $user = $this->paywalledUser();

        $this->actingAs($user)
            ->post(route('competitors.suggest'), [
                'platforms' => ['instagram'],
                'brief' => 'enough brief text here',
            ])
            ->assertRedirect(route('billing.edit'));
    }

    public function test_mcp_list_feed_blocked_when_paywalled(): void
    {
        $user = $this->paywalledUser();
        $this->actingAs($user);

        $blocked = McpAuth::requireProductAccess($user);
        $this->assertNotNull($blocked);

        SnitchServer::tool(ListCompetitorsTool::class)
            ->assertHasErrors()
            ->assertSee('Subscribe');
    }

    public function test_unclaimed_agent_starts_without_product_access(): void
    {
        $user = User::factory()->unclaimedAgent()->create();

        $this->assertFalse($this->billing->canAccessProduct($user));
        $this->assertSame(0.0, $this->billing->balancePence($user));
        $this->assertNull($user->trial_ends_at);
        $this->assertFalse($this->billing->isOnWebTrial($user));
    }

    public function test_claimed_web_user_starts_trial_with_starter_credit(): void
    {
        $user = User::factory()->create();

        $this->assertTrue($user->onGenericTrial());
        $this->assertTrue($this->billing->isOnWebTrial($user));
        $this->assertSame(500.0, $this->billing->balancePence($user));
        $this->assertTrue($this->billing->canAccessProduct($user));
        $this->assertFalse($this->billing->paywallState($user)['blocked']);
    }

    public function test_trial_end_blocks_access_even_with_remaining_starter(): void
    {
        $user = User::factory()->create();

        $this->assertTrue($this->billing->canAccessProduct($user));

        $this->travel(8)->days();

        $state = $this->billing->paywallState($user->fresh());

        $this->assertFalse($this->billing->isOnWebTrial($user->fresh()));
        $this->assertSame(500.0, $this->billing->balancePence($user->fresh()));
        $this->assertTrue($state['blocked']);
        $this->assertSame('subscribe', $state['reason']);
        $this->assertStringContainsString('trial has ended', (string) $state['message']);
        $this->assertFalse($this->billing->canAccessProduct($user->fresh()));
    }

    public function test_mcp_http_blocks_product_tools_with_402_when_paywalled(): void
    {
        $user = $this->paywalledUser();
        $token = $user->createSanctumToken('mcp')->plainTextToken;

        foreach (['list_feed', 'list_competitors', 'analyze_post'] as $tool) {
            $this->withToken($token)
                ->postJson('/mcp', $this->mcpToolCall($tool, $tool === 'analyze_post' ? ['post_id' => 1] : []))
                ->assertStatus(402)
                ->assertJsonPath('error.code', -32001)
                ->assertJsonPath('error.data.paywall.blocked', true)
                ->assertJsonPath('error.data.paywall.reason', 'subscribe');
        }
    }

    public function test_mcp_http_allows_billing_tools_when_paywalled(): void
    {
        $user = $this->paywalledUser();
        $token = $user->createSanctumToken('mcp')->plainTextToken;

        foreach (EnsureMcpProductAccess::ALLOWED_TOOLS as $tool) {
            $params = $tool === 'create_credit_checkout' ? ['pack' => 'pack_10'] : [];

            $response = $this->withToken($token)
                ->postJson('/mcp', $this->mcpToolCall($tool, $params));

            $this->assertNotSame(
                402,
                $response->status(),
                "Allowlisted MCP tool [{$tool}] should not return HTTP 402 when paywalled.",
            );
        }
    }

    public function test_mcp_tools_work_with_starter_credit(): void
    {
        $user = User::factory()->create();
        $this->billing->creditClaimBonus($user);
        $this->actingAs($user);

        SnitchServer::tool(ListFeedTool::class, ['limit' => 5])->assertOk();
        SnitchServer::tool(ListCompetitorsTool::class)->assertOk()->assertSee('competitors');
        SnitchServer::tool(BillingStatusTool::class)
            ->assertOk()
            ->assertSee('"can_run_billable":true')
            ->assertSee('"blocked":false');
    }

    public function test_mcp_create_credit_checkout_errors_without_paid_plan(): void
    {
        $user = User::factory()->create();
        $this->billing->creditClaimBonus($user);
        $this->actingAs($user);

        SnitchServer::tool(CreateCreditCheckoutTool::class, ['pack' => 'pack_10'])
            ->assertHasErrors()
            ->assertSee('active paid plan');
    }

    public function test_mcp_product_tools_restore_after_subscribe_with_balance(): void
    {
        $user = $this->paywalledUser();
        $this->subscribe($user);
        $this->billing->creditSubscriptionBonus($user, 'subscription_bonus:invoice:restore');
        $token = $user->createSanctumToken('mcp')->plainTextToken;

        $this->withToken($token)
            ->postJson('/mcp', $this->mcpToolCall('list_feed', ['limit' => 5]))
            ->assertSuccessful();

        $this->actingAs($user);
        SnitchServer::tool(ListCompetitorsTool::class)->assertOk();
    }

    public function test_subscribed_user_blocked_reason_is_credits_when_balance_floor_hit(): void
    {
        $user = User::factory()->withoutStarterCredit()->create();
        $this->subscribe($user);
        $this->billing->creditFromTopUp($user, 21, 'topup:floor');
        $this->billing->charge($user, 'explore.search', BillingVendor::Snitch);

        while ($this->billing->balancePence($user) > 20) {
            $this->billing->charge($user, 'explore.view', BillingVendor::Snitch);
        }

        $state = $this->billing->paywallState($user);

        $this->assertTrue($state['blocked']);
        $this->assertSame('credits', $state['reason']);
        $this->assertTrue($state['can_top_up']);
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array{jsonrpc: string, id: int, method: string, params: array{name: string, arguments: array<string, mixed>}}
     */
    private function mcpToolCall(string $name, array $arguments = []): array
    {
        return [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => [
                'name' => $name,
                'arguments' => $arguments,
            ],
        ];
    }

    private function paywalledUser(): User
    {
        $user = User::factory()->create();
        BrandProfile::factory()->for($user)->create();
        $this->billing->creditClaimBonus($user);

        while ($this->billing->canAccessProduct($user)) {
            $this->billing->charge($user, 'explore.search', BillingVendor::Snitch);
        }

        return $user->fresh();
    }

    private function subscribe(User $user): Subscription
    {
        $subscription = $user->subscriptions()->create([
            'type' => 'default',
            'stripe_id' => 'sub_'.uniqid(),
            'stripe_status' => 'active',
            'stripe_price' => 'price_platform_test',
            'quantity' => 1,
        ]);

        $user->unsetRelation('subscriptions');

        return $subscription;
    }
}
