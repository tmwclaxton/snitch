<?php

namespace Tests\Feature\Billing;

use App\Models\User;
use App\Services\Billing\PlanEntitlementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Cashier\SubscriptionBuilder;
use Mockery;
use Tests\TestCase;

class BillingCheckoutTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'subscriptions.plans.basic.stripe_price' => 'price_basic_test',
            'subscriptions.plans.pro.stripe_price' => 'price_pro_test',
            'cashier.webhook.secret' => null,
        ]);
    }

    public function test_billing_page_is_displayed(): void
    {
        $user = User::factory()->onTrial()->create();

        $this->actingAs($user)
            ->get(route('billing.edit'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('settings/Billing')
                ->has('subscription')
                ->has('plans')
                ->where('subscription.plan', 'basic')
                ->where('subscription.on_trial', true));
    }

    public function test_checkout_redirects_when_price_missing(): void
    {
        config(['subscriptions.plans.basic.stripe_price' => null]);

        $user = User::factory()->onTrial()->create();

        $this->actingAs($user)
            ->post(route('billing.checkout'), ['plan' => 'basic'])
            ->assertRedirect(route('billing.edit'));
    }

    public function test_checkout_redirects_to_stripe_checkout_session(): void
    {
        $user = User::factory()->onTrial()->create();

        $builder = Mockery::mock(SubscriptionBuilder::class);
        $builder->shouldReceive('checkout')
            ->once()
            ->andReturn(redirect()->away('https://checkout.stripe.com/c/pay/cs_test_123'));

        $user = Mockery::mock($user)->makePartial();
        $user->shouldReceive('newSubscription')
            ->once()
            ->with('default', 'price_basic_test')
            ->andReturn($builder);

        $this->actingAs($user)
            ->post(route('billing.checkout'), ['plan' => 'basic'])
            ->assertRedirect('https://checkout.stripe.com/c/pay/cs_test_123');
    }

    public function test_webhook_subscription_created_grants_basic_limit(): void
    {
        $user = User::factory()->freePlan()->create([
            'stripe_id' => 'cus_billing_test',
        ]);

        $payload = [
            'id' => 'evt_test_subscription_created',
            'type' => 'customer.subscription.created',
            'data' => [
                'object' => [
                    'id' => 'sub_billing_basic',
                    'customer' => 'cus_billing_test',
                    'status' => 'active',
                    'trial_end' => null,
                    'metadata' => [
                        'type' => 'default',
                        'name' => 'default',
                    ],
                    'items' => [
                        'data' => [
                            [
                                'id' => 'si_billing_basic',
                                'quantity' => 1,
                                'price' => [
                                    'id' => 'price_basic_test',
                                    'product' => 'prod_basic_test',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $this->postJson('/stripe/webhook', $payload)
            ->assertOk();

        $user->refresh();

        $this->assertTrue($user->subscribed('default'));
        $this->assertSame('basic', app(PlanEntitlementService::class)->plan($user));
        $this->assertSame(10, app(PlanEntitlementService::class)->competitorLimit($user));
        $this->assertNull($user->trial_ends_at);
    }
}
