<?php

namespace Tests\Feature\Billing;

use App\Models\User;
use App\Services\Billing\PlanEntitlementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Cashier\Cashier;
use Tests\TestCase;

/**
 * Hits the real Stripe test API with sk_test / price IDs from env.
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
        $platform = (string) config('billing.platform_stripe_price');

        if ($secret === '' || ! str_starts_with($secret, 'sk_test_')) {
            $this->markTestSkipped('STRIPE_SECRET must be a sk_test_ key for Stripe plan integration tests.');
        }

        if ($platform === '' || ! str_starts_with($platform, 'price_')) {
            $this->markTestSkipped('STRIPE_PRICE_PLATFORM must be set to a real Stripe Price ID.');
        }

        $this->entitlements = app(PlanEntitlementService::class);
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

    public function test_stripe_platform_subscription_marks_user_subscribed(): void
    {
        $user = $this->makeBillableUser();

        $user->newSubscription('default', (string) config('billing.platform_stripe_price'))
            ->create('pm_card_visa');

        $user->refresh();

        $this->assertTrue($user->subscribed('default'));
        $this->assertSame('platform', $this->entitlements->plan($user));
        $this->assertTrue($this->entitlements->hasPlatformSubscription($user));
    }

    private function makeBillableUser(): User
    {
        $user = User::factory()->create([
            'email' => 'stripe-platform-'.uniqid().'@example.com',
        ]);
        $user->createAsStripeCustomer();
        $this->customersToDelete[] = $user;

        return $user;
    }
}
