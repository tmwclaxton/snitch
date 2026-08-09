<?php

namespace Tests\Feature\Billing;

use App\Models\User;
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
            'billing.platform_stripe_price' => 'price_platform_test',
            'billing.credit_packs.pack_10.stripe_price' => 'price_credits_10_test',
            'cashier.webhook.secret' => null,
        ]);
    }

    public function test_billing_page_is_displayed(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('billing.edit'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('billing/Index')
                ->has('subscription')
                ->has('usage')
                ->has('creditPacks')
                ->has('platform')
                ->where('subscription.subscribed', false)
                ->where('usage.balance_pence', 0)
                ->where('platform.fee_pence', 1900));
    }

    public function test_checkout_redirects_when_platform_price_missing(): void
    {
        config(['billing.platform_stripe_price' => null]);

        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('billing.checkout'), ['product' => 'platform'])
            ->assertRedirect(route('billing.edit'));
    }

    public function test_platform_checkout_uses_inertia_location(): void
    {
        $user = User::factory()->create();

        $session = (object) ['url' => 'https://checkout.stripe.com/c/pay/cs_test_123'];
        $checkout = Mockery::mock(Checkout::class);
        $checkout->shouldReceive('asStripeCheckoutSession')->once()->andReturn($session);

        $builder = Mockery::mock(SubscriptionBuilder::class);
        $builder->shouldReceive('checkout')->once()->andReturn($checkout);

        $user = Mockery::mock($user)->makePartial();
        $user->shouldReceive('subscribed')->andReturn(false);
        $user->shouldReceive('newSubscription')->once()->andReturn($builder);
        $this->actingAs($user);

        $response = $this->post(route('billing.checkout'), ['product' => 'platform']);

        $response->assertRedirect('https://checkout.stripe.com/c/pay/cs_test_123');
    }
}
