<?php

namespace Tests\Feature\Billing;

use App\Enums\BillingVendor;
use App\Exceptions\InsufficientCreditsException;
use App\Exceptions\PlatformSubscriptionRequiredException;
use App\Mcp\Support\McpAuth;
use App\Models\BrandProfile;
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
        $user = User::factory()->create();
        $this->subscribe($user);
        $this->billing->creditSubscriptionBonus($user, 'subscription_bonus:invoice:in_access');

        $this->assertTrue($this->billing->canAccessProduct($user));
        $this->assertTrue($this->billing->paywallState($user)['can_top_up']);
    }

    public function test_subscribed_user_blocked_when_monthly_allowance_spent(): void
    {
        $user = User::factory()->create();
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
        $user = User::factory()->create();
        $entry = $this->billing->creditClaimBonus($user);

        $this->assertNotNull($entry);
        $this->assertNull($entry->expires_at);
        $this->assertSame(500.0, (float) $entry->remaining_pence);

        $this->travel(400)->days();

        $this->assertSame(500.0, $this->billing->balancePence($user));
        $this->assertTrue($this->billing->canAccessProduct($user));
    }

    public function test_subscription_bonus_expires_at_month_end(): void
    {
        $this->travelTo(now()->startOfMonth()->addDays(2)->setTime(12, 0));

        $user = User::factory()->create();
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
        $user = User::factory()->create();
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
        $user = User::factory()->create();
        BrandProfile::factory()->for($user)->create();
        $this->billing->creditClaimBonus($user);

        while ($this->billing->canAccessProduct($user)) {
            $this->billing->charge($user, 'explore.search', BillingVendor::Snitch);
        }

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('subscription.can_run_billable', false)
                ->where('subscription.paywall.blocked', true)
                ->where('subscription.paywall.reason', 'subscribe')
                ->where('subscription.paywall.can_top_up', false)
            );
    }

    public function test_product_mutation_redirects_when_paywalled(): void
    {
        $user = User::factory()->create();
        BrandProfile::factory()->for($user)->create();

        $this->actingAs($user)
            ->post(route('competitors.suggest'), [
                'platforms' => ['instagram'],
                'brief' => 'enough brief text here',
            ])
            ->assertRedirect(route('billing.edit'));
    }

    public function test_mcp_list_feed_blocked_when_paywalled(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $blocked = McpAuth::requireProductAccess($user);
        $this->assertNotNull($blocked);
    }

    private function subscribe(User $user): Subscription
    {
        return $user->subscriptions()->create([
            'type' => 'default',
            'stripe_id' => 'sub_'.uniqid(),
            'stripe_status' => 'active',
            'stripe_price' => 'price_platform_test',
            'quantity' => 1,
        ]);
    }
}
