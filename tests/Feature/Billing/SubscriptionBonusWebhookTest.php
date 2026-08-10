<?php

namespace Tests\Feature\Billing;

use App\Listeners\HandleStripeWebhook;
use App\Models\User;
use App\Services\Billing\UsageBillingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Cashier\Events\WebhookReceived;
use Tests\TestCase;

class SubscriptionBonusWebhookTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'billing.platform_stripe_price' => 'price_platform_test',
            'billing.subscription_bonus_pence' => 3000,
        ]);
    }

    public function test_invoice_paid_grants_subscription_bonus_credits(): void
    {
        $user = User::factory()->create([
            'stripe_id' => 'cus_test_bonus',
        ]);

        $listener = app(HandleStripeWebhook::class);
        $listener->handle(new WebhookReceived([
            'type' => 'invoice.paid',
            'data' => [
                'object' => [
                    'id' => 'in_test_bonus_1',
                    'status' => 'paid',
                    'customer' => 'cus_test_bonus',
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
            ],
        ]));

        $this->assertSame(3000.0, app(UsageBillingService::class)->balancePence($user));

        $listener->handle(new WebhookReceived([
            'type' => 'invoice.paid',
            'data' => [
                'object' => [
                    'id' => 'in_test_bonus_1',
                    'status' => 'paid',
                    'customer' => 'cus_test_bonus',
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
            ],
        ]));

        $this->assertSame(3000.0, app(UsageBillingService::class)->balancePence($user));
    }

    public function test_invoice_paid_ignores_non_platform_prices(): void
    {
        $user = User::factory()->create([
            'stripe_id' => 'cus_test_other',
        ]);

        app(HandleStripeWebhook::class)->handle(new WebhookReceived([
            'type' => 'invoice.paid',
            'data' => [
                'object' => [
                    'id' => 'in_test_other_1',
                    'status' => 'paid',
                    'customer' => 'cus_test_other',
                    'lines' => [
                        'data' => [
                            [
                                'price' => [
                                    'id' => 'price_something_else',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]));

        $this->assertSame(0.0, app(UsageBillingService::class)->balancePence($user));
    }
}
