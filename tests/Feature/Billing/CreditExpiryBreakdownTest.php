<?php

namespace Tests\Feature\Billing;

use App\Models\User;
use App\Services\Billing\UsageBillingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Cashier\Subscription;
use Tests\TestCase;

class CreditExpiryBreakdownTest extends TestCase
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
            'billing.topup_expiry_months' => 3,
            'billing.min_run_balance_pence' => 20,
        ]);

        $this->billing = app(UsageBillingService::class);
    }

    public function test_credit_expiry_breakdown_groups_lots_by_expiry(): void
    {
        $this->travelTo(now()->startOfMonth()->addDays(5)->setTime(12, 0));

        $user = User::factory()->withoutStarterCredit()->create();
        $this->subscribe($user);

        $this->billing->creditClaimBonus($user);
        $this->billing->creditSubscriptionBonus($user, 'subscription_bonus:invoice:in_1');
        $this->billing->creditFromTopUp($user, 1000, 'topup:expiry-test');

        $breakdown = $this->billing->creditExpiryBreakdown($user);

        $this->assertSame(4500.0, $breakdown['total_remaining_pence']);
        $this->assertSame(3, $breakdown['topup_expiry_months']);
        $this->assertCount(3, $breakdown['buckets']);

        $this->assertSame('End of '.now()->format('M Y'), $breakdown['buckets'][0]['expires_label']);
        $this->assertSame(3000.0, $breakdown['buckets'][0]['remaining_pence']);
        $this->assertSame('subscription_bonus', $breakdown['buckets'][0]['lots'][0]['action']);

        $this->assertSame('Never', $breakdown['buckets'][2]['expires_label']);
        $this->assertNull($breakdown['buckets'][2]['expires_at']);
        $this->assertSame(500.0, $breakdown['buckets'][2]['remaining_pence']);
    }

    public function test_credit_expiry_filter_note_for_top_up_action(): void
    {
        $note = $this->billing->creditExpiryFilterNote('credits.topup');

        $this->assertNotNull($note);
        $this->assertSame('Top-up credits', $note['title']);
        $this->assertStringContainsString('3 months', $note['body']);
    }

    public function test_billing_index_includes_credit_expiry_breakdown(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('billing.edit'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('billing/Index')
                ->has('usage.credit_expiry.buckets')
                ->where('usage.credit_expiry.topup_expiry_months', 3)
                ->where('usage.credit_expiry.total_remaining_pence', fn ($value) => (float) $value === 500.0));
    }

    public function test_billing_charges_includes_credit_expiry_and_filter_note(): void
    {
        $user = User::factory()->withoutStarterCredit()->create();
        $this->subscribe($user);
        $this->billing->creditFromTopUp($user, 1000, 'topup:charges-note');

        $this->actingAs($user)
            ->get(route('billing.charges', ['action' => 'credits.topup']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('billing/Charges')
                ->has('creditExpiry.buckets')
                ->where('topupExpiryMonths', 3)
                ->where('creditExpiryNote.title', 'Top-up credits')
                ->where('filters.action', 'credits.topup'));
    }

    private function subscribe(User $user): void
    {
        Subscription::query()->create([
            'user_id' => $user->id,
            'type' => config('billing.subscription_type', 'default'),
            'stripe_id' => 'sub_test_'.$user->id,
            'stripe_status' => 'active',
            'stripe_price' => 'price_platform_test',
            'quantity' => 1,
        ]);
    }
}
