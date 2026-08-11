<?php

namespace Tests\Concerns;

use App\Models\User;
use App\Services\Billing\UsageBillingService;
use Laravel\Cashier\Subscription;

trait WithPlatformBilling
{
    protected function enablePlatformBilling(User $user, int $creditsPence = 50_000): void
    {
        config([
            'billing.platform_stripe_price' => 'price_platform_test',
        ]);

        $user->subscriptions()->create([
            'type' => 'default',
            'stripe_id' => 'sub_'.uniqid(),
            'stripe_status' => 'active',
            'stripe_price' => 'price_platform_test',
            'quantity' => 1,
        ]);

        $user->unsetRelation('subscriptions');
        $user->refresh();

        if ($creditsPence > 0) {
            app(UsageBillingService::class)->creditFromTopUp(
                $user,
                $creditsPence,
                'test-topup:'.$user->id.':'.uniqid(),
            );
        }
    }

    protected function createPlatformSubscription(User $user): Subscription
    {
        $subscription = $user->subscriptions()->create([
            'type' => 'default',
            'stripe_id' => 'sub_'.uniqid(),
            'stripe_status' => 'active',
            'stripe_price' => (string) config('billing.platform_stripe_price', 'price_platform_test'),
            'quantity' => 1,
        ]);

        $user->unsetRelation('subscriptions');

        return $subscription;
    }
}
