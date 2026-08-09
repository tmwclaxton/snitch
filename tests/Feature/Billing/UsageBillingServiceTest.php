<?php

namespace Tests\Feature\Billing;

use App\Enums\BillingVendor;
use App\Exceptions\InsufficientCreditsException;
use App\Exceptions\PlatformSubscriptionRequiredException;
use App\Models\User;
use App\Services\Billing\UsageBillingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Cashier\Subscription;
use Tests\TestCase;

class UsageBillingServiceTest extends TestCase
{
    use RefreshDatabase;

    private UsageBillingService $billing;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'billing.platform_stripe_price' => 'price_platform_test',
            'billing.claim_bonus_pence' => 500,
            'billing.price_multiplier' => 1.4,
            'billing.usd_to_gbp' => 1.0,
        ]);

        $this->billing = app(UsageBillingService::class);
    }

    public function test_agent_starts_with_zero_credits(): void
    {
        $user = User::factory()->unclaimedAgent()->create();

        $this->assertSame(0, $this->billing->balancePence($user));
        $this->assertFalse($this->billing->hasPlatformSubscription($user));
    }

    public function test_claim_bonus_is_idempotent(): void
    {
        $user = User::factory()->create();

        $this->billing->creditClaimBonus($user);
        $this->billing->creditClaimBonus($user);

        $this->assertSame(500, $this->billing->balancePence($user));
    }

    public function test_charge_requires_platform_subscription(): void
    {
        $user = User::factory()->create();
        $this->billing->creditFromTopUp($user, 1000, 'topup:test');

        $this->expectException(PlatformSubscriptionRequiredException::class);

        $this->billing->charge($user, 'analyze.post', BillingVendor::NanoGpt, 0.04);
    }

    public function test_charge_deducts_credits_when_subscribed(): void
    {
        $user = User::factory()->create();
        $this->subscribe($user);
        $this->billing->creditFromTopUp($user, 1000, 'topup:test2');

        $entry = $this->billing->charge($user, 'analyze.post', BillingVendor::NanoGpt, 0.04);

        $this->assertLessThan(0, $entry->amount_pence);
        $this->assertSame(BillingVendor::NanoGpt, $entry->vendor);
        $this->assertSame(1000 + $entry->amount_pence, $this->billing->balancePence($user));
    }

    public function test_insufficient_credits_throws(): void
    {
        $user = User::factory()->create();
        $this->subscribe($user);

        $this->expectException(InsufficientCreditsException::class);

        $this->billing->charge($user, 'analyze.post', BillingVendor::NanoGpt, 0.04);
    }

    public function test_usage_summary_groups_vendors(): void
    {
        $user = User::factory()->create();
        $this->subscribe($user);
        $this->billing->creditFromTopUp($user, 5000, 'topup:sum');

        $this->billing->charge($user, 'sync.account', BillingVendor::Apify, 0.05);
        $this->billing->charge($user, 'analyze.post', BillingVendor::NanoGpt, 0.04);
        $this->billing->charge($user, 'brand.autofill', BillingVendor::Firecrawl, 0.01);

        $summary = $this->billing->summary($user);

        $this->assertGreaterThan(0, $summary['vendors']['apify']['spend_pence']);
        $this->assertGreaterThan(0, $summary['vendors']['nanogpt']['spend_pence']);
        $this->assertGreaterThan(0, $summary['vendors']['firecrawl']['spend_pence']);
        $this->assertArrayNotHasKey('markup', $summary);
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
