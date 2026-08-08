<?php

namespace Tests\Feature\Billing;

use App\Models\BrandProfile;
use App\Models\TrackedAccount;
use App\Models\User;
use App\Services\Billing\PlanEntitlementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Cashier\Cashier;
use Tests\TestCase;

/**
 * Hits the real Stripe test API with sk_test / price IDs from env.
 * Safe against production DB: PHPUnit forces sqlite :memory:.
 */
class StripePlanIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private PlanEntitlementService $entitlements;

    /** @var list<User> */
    private array $customersToDelete = [];

    protected function setUp(): void
    {
        parent::setUp();

        $secret = (string) config('cashier.secret');
        $basic = (string) config('subscriptions.plans.basic.stripe_price');
        $pro = (string) config('subscriptions.plans.pro.stripe_price');

        if ($secret === '' || ! str_starts_with($secret, 'sk_test_')) {
            $this->markTestSkipped('STRIPE_SECRET must be a sk_test_ key for Stripe plan integration tests.');
        }

        if ($basic === '' || $pro === '' || ! str_starts_with($basic, 'price_') || ! str_starts_with($pro, 'price_')) {
            $this->markTestSkipped('STRIPE_PRICE_BASIC and STRIPE_PRICE_PRO must be set to real Stripe Price IDs.');
        }

        $this->entitlements = app(PlanEntitlementService::class);
        Queue::fake();
    }

    protected function tearDown(): void
    {
        foreach ($this->customersToDelete as $user) {
            try {
                if ($user->subscription('default')) {
                    $user->subscription('default')->cancelNow();
                }

                if ($user->hasStripeId()) {
                    Cashier::stripe()->customers->delete($user->stripe_id);
                }
            } catch (\Throwable) {
                // Best-effort cleanup against Stripe test mode.
            }
        }

        parent::tearDown();
    }

    public function test_stripe_basic_subscription_grants_ten_competitor_slots(): void
    {
        $user = $this->makeBillableUser();

        $user->newSubscription('default', (string) config('subscriptions.plans.basic.stripe_price'))
            ->create('pm_card_visa');

        $user->refresh();

        $this->assertTrue($user->subscribed('default'));
        $this->assertSame('basic', $this->entitlements->plan($user));
        $this->assertSame(10, $this->entitlements->competitorLimit($user));
        $this->assertFalse($this->entitlements->onTrial($user));
    }

    public function test_stripe_pro_subscription_grants_fifty_competitor_slots(): void
    {
        $user = $this->makeBillableUser();

        $user->newSubscription('default', (string) config('subscriptions.plans.pro.stripe_price'))
            ->create('pm_card_visa');

        $user->refresh();

        $this->assertTrue($user->subscribed('default'));
        $this->assertSame('pro', $this->entitlements->plan($user));
        $this->assertSame(50, $this->entitlements->competitorLimit($user));
    }

    public function test_basic_subscriber_can_add_tenth_competitor_but_not_eleventh(): void
    {
        $user = $this->makeBillableUser();
        BrandProfile::factory()->for($user)->create();

        $user->newSubscription('default', (string) config('subscriptions.plans.basic.stripe_price'))
            ->create('pm_card_visa');

        TrackedAccount::factory()->count(9)->for($user)->create();

        $this->actingAs($user)
            ->post(route('competitors.store'), [
                'platform' => 'instagram',
                'handle' => 'basic_tenth',
            ])
            ->assertRedirect(route('competitors.index'));

        $this->assertDatabaseHas('tracked_accounts', [
            'user_id' => $user->id,
            'handle' => 'basic_tenth',
        ]);

        $this->actingAs($user)
            ->post(route('competitors.store'), [
                'platform' => 'tiktok',
                'handle' => 'basic_eleventh',
            ])
            ->assertRedirect(route('competitors.index'));

        $this->assertDatabaseMissing('tracked_accounts', [
            'user_id' => $user->id,
            'handle' => 'basic_eleventh',
        ]);
    }

    public function test_checkout_route_creates_real_stripe_checkout_session_for_basic(): void
    {
        $user = $this->makeBillableUser();

        $response = $this->actingAs($user)
            ->post(route('billing.checkout'), ['plan' => 'basic', 'interval' => 'month']);

        $response->assertRedirect();
        $location = (string) $response->headers->get('Location');

        $this->assertNotSame('', $location);
        $this->assertTrue(
            str_contains($location, 'checkout.stripe.com') || str_starts_with($location, 'https://checkout.stripe.com'),
            "Expected Stripe Checkout URL, got: {$location}",
        );

        $user->refresh();
        $this->assertTrue($user->hasStripeId());
        $this->customersToDelete[] = $user;
    }

    public function test_checkout_route_creates_real_stripe_checkout_session_for_pro(): void
    {
        $user = $this->makeBillableUser();

        $response = $this->actingAs($user)
            ->post(route('billing.checkout'), ['plan' => 'pro', 'interval' => 'month']);

        $response->assertRedirect();
        $location = (string) $response->headers->get('Location');

        $this->assertTrue(
            str_contains($location, 'checkout.stripe.com'),
            "Expected Stripe Checkout URL, got: {$location}",
        );

        $user->refresh();
        $this->customersToDelete[] = $user;
    }

    public function test_canceled_subscription_falls_back_to_free_after_trial_expired(): void
    {
        $user = $this->makeBillableUser(onTrial: false);

        $user->newSubscription('default', (string) config('subscriptions.plans.basic.stripe_price'))
            ->create('pm_card_visa');

        $user->subscription('default')?->cancelNow();
        $user->refresh();

        $this->assertFalse($user->subscribed('default'));
        $this->assertSame('free', $this->entitlements->plan($user));
        $this->assertSame(3, $this->entitlements->competitorLimit($user));
    }

    public function test_stripe_price_ids_resolve_to_expected_gbp_amounts(): void
    {
        $stripe = Cashier::stripe();
        $basic = $stripe->prices->retrieve((string) config('subscriptions.plans.basic.stripe_price'));
        $pro = $stripe->prices->retrieve((string) config('subscriptions.plans.pro.stripe_price'));

        $this->assertSame('gbp', $basic->currency);
        $this->assertSame(2000, $basic->unit_amount);
        $this->assertSame('month', $basic->recurring?->interval);

        $this->assertSame('gbp', $pro->currency);
        $this->assertSame(9900, $pro->unit_amount);
        $this->assertSame('month', $pro->recurring?->interval);

        $basicYearly = (string) config('subscriptions.plans.basic.stripe_price_yearly');
        $proYearly = (string) config('subscriptions.plans.pro.stripe_price_yearly');

        if ($basicYearly === '' || $proYearly === '') {
            $this->markTestSkipped('Yearly Stripe price IDs are not configured yet.');
        }

        $basicY = $stripe->prices->retrieve($basicYearly);
        $proY = $stripe->prices->retrieve($proYearly);

        $this->assertSame(19200, $basicY->unit_amount);
        $this->assertSame('year', $basicY->recurring?->interval);
        $this->assertSame(95040, $proY->unit_amount);
        $this->assertSame('year', $proY->recurring?->interval);
    }

    private function makeBillableUser(bool $onTrial = true): User
    {
        $user = $onTrial
            ? User::factory()->onTrial()->create()
            : User::factory()->freePlan()->create();

        $this->customersToDelete[] = $user;

        return $user;
    }
}
