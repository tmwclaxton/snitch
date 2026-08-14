<?php

namespace Tests\Feature\Billing;

use App\Models\User;
use App\Services\Billing\PlanEntitlementService;
use App\Services\Billing\StripeCheckoutSyncService;
use App\Services\Billing\UsageBillingService;
use App\Support\GoogleAnalytics;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CheckoutSuccessSyncTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'billing.platform_stripe_price' => 'price_platform_test',
            'billing.subscription_bonus_pence' => 3000,
            'billing.credit_packs.pack_10.stripe_price' => 'price_credits_10_test',
            'billing.credit_packs.pack_10.credits_pence' => 1000,
        ]);
    }

    public function test_apply_checkout_session_syncs_platform_subscription_and_bonus(): void
    {
        $user = User::factory()->withoutStarterCredit()->create([
            'stripe_id' => 'cus_checkout_sync',
        ]);

        $applied = app(StripeCheckoutSyncService::class)->applyCheckoutSession($user, [
            'id' => 'cs_test_platform',
            'customer' => 'cus_checkout_sync',
            'mode' => 'subscription',
            'status' => 'complete',
            'payment_status' => 'paid',
            'amount_total' => 1900,
            'currency' => 'gbp',
            'invoice' => [
                'id' => 'in_checkout_sync_1',
                'status' => 'paid',
                'billing_reason' => 'subscription_create',
                'amount_paid' => 1900,
                'lines' => [
                    'data' => [
                        [
                            'price' => [
                                'id' => 'price_platform_test',
                            ],
                        ],
                    ],
                ],
            ],
            'subscription' => [
                'id' => 'sub_checkout_sync_1',
                'status' => 'active',
                'metadata' => [
                    'type' => 'default',
                    'name' => 'default',
                ],
                'items' => [
                    'data' => [
                        [
                            'id' => 'si_checkout_sync_1',
                            'quantity' => 1,
                            'price' => [
                                'id' => 'price_platform_test',
                                'product' => 'prod_platform_test',
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $user->refresh();

        $this->assertTrue($applied);
        $this->assertTrue($user->subscribed('default'));
        $this->assertTrue(app(PlanEntitlementService::class)->hasPlatformSubscription($user));
        $this->assertSame(3000.0, app(UsageBillingService::class)->balancePence($user));

        $events = GoogleAnalytics::takeEvents();
        $this->assertSame('purchase', $events[0]['name'] ?? null);
        $this->assertSame('cs_test_platform', $events[0]['params']['transaction_id'] ?? null);
        $this->assertSame(19.0, $events[0]['params']['value'] ?? null);
        $this->assertSame('GBP', $events[0]['params']['currency'] ?? null);
    }

    public function test_apply_checkout_session_credits_top_up_when_subscribed(): void
    {
        $user = User::factory()->create([
            'stripe_id' => 'cus_checkout_credits',
        ]);
        $user->subscriptions()->create([
            'type' => 'default',
            'stripe_id' => 'sub_existing_'.uniqid(),
            'stripe_status' => 'active',
            'stripe_price' => 'price_platform_test',
            'quantity' => 1,
        ]);
        $user->unsetRelation('subscriptions');

        $before = app(UsageBillingService::class)->balancePence($user);

        $applied = app(StripeCheckoutSyncService::class)->applyCheckoutSession($user, [
            'id' => 'cs_test_credits',
            'customer' => 'cus_checkout_credits',
            'mode' => 'payment',
            'status' => 'complete',
            'payment_status' => 'paid',
            'amount_total' => 1000,
            'currency' => 'gbp',
            'metadata' => [
                'snitch_product' => 'credits',
                'credit_pack' => 'pack_10',
                'credits_pence' => '1000',
                'user_id' => (string) $user->id,
            ],
        ]);

        $this->assertTrue($applied);
        $this->assertSame($before + 1000.0, app(UsageBillingService::class)->balancePence($user));
        $this->assertSame('credits', GoogleAnalytics::takeEvents()[0]['params']['items'][0]['item_id'] ?? null);
    }

    public function test_apply_checkout_session_rejects_customer_mismatch(): void
    {
        $user = User::factory()->create([
            'stripe_id' => 'cus_expected',
        ]);

        $applied = app(StripeCheckoutSyncService::class)->applyCheckoutSession($user, [
            'id' => 'cs_mismatch',
            'customer' => 'cus_other',
            'subscription' => [
                'id' => 'sub_mismatch',
                'status' => 'active',
                'metadata' => ['type' => 'default'],
                'items' => [
                    'data' => [
                        [
                            'id' => 'si_mismatch',
                            'quantity' => 1,
                            'price' => [
                                'id' => 'price_platform_test',
                                'product' => 'prod_platform_test',
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $this->assertFalse($applied);
        $this->assertFalse($user->fresh()->subscribed('default'));
    }

    public function test_billing_success_return_invokes_checkout_sync(): void
    {
        $user = User::factory()->create([
            'stripe_id' => 'cus_return_sync',
        ]);

        $this->mock(StripeCheckoutSyncService::class, function ($mock) use ($user): void {
            $mock->shouldReceive('syncUserFromCheckoutReturn')
                ->once()
                ->withArgs(function (User $syncedUser, ?string $sessionId) use ($user): bool {
                    return $syncedUser->is($user) && $sessionId === 'cs_from_stripe';
                })
                ->andReturn(true);
        });

        $this->actingAs($user)
            ->get('/billing?checkout=success&session_id=cs_from_stripe')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('billing/Index'));
    }

    public function test_platform_checkout_success_url_includes_session_placeholder(): void
    {
        $this->assertStringContainsString(
            'session_id={CHECKOUT_SESSION_ID}',
            StripeCheckoutSyncService::billingSuccessUrl('success'),
        );
        $this->assertStringContainsString(
            'checkout=success',
            StripeCheckoutSyncService::billingSuccessUrl('success'),
        );
    }
}
