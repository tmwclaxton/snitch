<?php

namespace Tests\Feature\Billing;

use App\Models\User;
use App\Services\Billing\PlanEntitlementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Cashier\Checkout;
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
                ->component('billing/Index')
                ->has('subscription')
                ->has('plans')
                ->where('subscription.plan', 'basic')
                ->where('subscription.on_trial', true)
                ->where('subscription.subscribed', false));
    }

    public function test_checkout_redirects_when_price_missing(): void
    {
        config(['subscriptions.plans.basic.stripe_price' => null]);

        $user = User::factory()->onTrial()->create();

        $this->actingAs($user)
            ->post(route('billing.checkout'), ['plan' => 'basic'])
            ->assertRedirect(route('billing.edit'));
    }

    public function test_checkout_uses_inertia_location_for_stripe_session(): void
    {
        $user = User::factory()->onTrial()->create();

        $session = (object) ['url' => 'https://checkout.stripe.com/c/pay/cs_test_123'];
        $checkout = Mockery::mock(Checkout::class);
        $checkout->shouldReceive('asStripeCheckoutSession')->once()->andReturn($session);

        $builder = Mockery::mock(SubscriptionBuilder::class);
        $builder->shouldReceive('checkout')->once()->andReturn($checkout);

        $user = Mockery::mock($user)->makePartial();
        $user->shouldReceive('subscribed')->with('default')->andReturn(false);
        $user->shouldReceive('newSubscription')
            ->once()
            ->with('default', 'price_basic_test')
            ->andReturn($builder);

        $this->actingAs($user)
            ->post(route('billing.checkout'), ['plan' => 'basic'], [
                'X-Inertia' => 'true',
            ])
            ->assertStatus(409)
            ->assertHeader('X-Inertia-Location', 'https://checkout.stripe.com/c/pay/cs_test_123');
    }

    public function test_pro_checkout_uses_inertia_location(): void
    {
        $user = User::factory()->onTrial()->create();

        $session = (object) ['url' => 'https://checkout.stripe.com/c/pay/cs_test_pro'];
        $checkout = Mockery::mock(Checkout::class);
        $checkout->shouldReceive('asStripeCheckoutSession')->once()->andReturn($session);

        $builder = Mockery::mock(SubscriptionBuilder::class);
        $builder->shouldReceive('checkout')->once()->andReturn($checkout);

        $user = Mockery::mock($user)->makePartial();
        $user->shouldReceive('subscribed')->with('default')->andReturn(false);
        $user->shouldReceive('newSubscription')
            ->once()
            ->with('default', 'price_pro_test')
            ->andReturn($builder);

        $this->actingAs($user)
            ->post(route('billing.checkout'), ['plan' => 'pro'], [
                'X-Inertia' => 'true',
            ])
            ->assertStatus(409)
            ->assertHeader('X-Inertia-Location', 'https://checkout.stripe.com/c/pay/cs_test_pro');
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
