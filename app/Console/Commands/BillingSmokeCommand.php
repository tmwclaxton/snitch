<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Billing\PlanEntitlementService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Laravel\Cashier\Cashier;
use Throwable;

#[Signature('snitch:billing-smoke {--keep : Keep the temporary Stripe customer and local user}')]
#[Description('Smoke-test Stripe Basic/Pro prices, Checkout, and subscription entitlements against the configured keys')]
class BillingSmokeCommand extends Command
{
    public function handle(PlanEntitlementService $entitlements): int
    {
        $secret = (string) config('cashier.secret');
        $basicPrice = (string) config('subscriptions.plans.basic.stripe_price');
        $proPrice = (string) config('subscriptions.plans.pro.stripe_price');

        if ($secret === '' || ! str_starts_with($secret, 'sk_test_')) {
            $this->error('Billing smoke requires STRIPE_SECRET to be an sk_test_ key.');

            return self::FAILURE;
        }

        if ($basicPrice === '' || $proPrice === '') {
            $this->error('STRIPE_PRICE_BASIC and STRIPE_PRICE_PRO must be set.');

            return self::FAILURE;
        }

        $user = null;

        try {
            $stripe = Cashier::stripe();

            $this->info('Checking catalog prices...');
            $basic = $stripe->prices->retrieve($basicPrice);
            $pro = $stripe->prices->retrieve($proPrice);

            $this->assertSame('gbp', $basic->currency, 'Basic currency');
            $this->assertSame(2000, $basic->unit_amount, 'Basic amount');
            $this->assertSame('month', $basic->recurring?->interval, 'Basic interval');
            $this->assertSame('gbp', $pro->currency, 'Pro currency');
            $this->assertSame(9900, $pro->unit_amount, 'Pro amount');
            $this->assertSame('month', $pro->recurring?->interval, 'Pro interval');
            $this->line('  catalog OK');

            $user = $this->makeSmokeUser(
                email: 'billing-smoke+'.uniqid().'@snitchsocial.net',
                trialEndsAt: now()->subDay(),
            );

            $this->info("Created smoke user #{$user->id}");

            $this->info('Creating Basic subscription with pm_card_visa...');
            $user->newSubscription('default', $basicPrice)->create('pm_card_visa');
            $user->refresh();

            $this->assertTrue($user->subscribed('default'), 'subscribed basic');
            $this->assertSame('basic', $entitlements->plan($user), 'plan basic');
            $this->assertSame(10, $entitlements->competitorLimit($user), 'basic limit');
            $this->line('  basic subscription OK');

            $this->info('Swapping to Pro...');
            $user->subscription('default')?->swap($proPrice);
            $user->refresh();

            $this->assertSame('pro', $entitlements->plan($user), 'plan pro');
            $this->assertSame(50, $entitlements->competitorLimit($user), 'pro limit');
            $this->line('  pro swap OK');

            $this->info('Creating Checkout session for Basic...');
            $checkoutUser = $this->makeSmokeUser(
                email: 'billing-checkout+'.uniqid().'@snitchsocial.net',
                trialEndsAt: now()->addDays(7),
            );

            $checkout = $checkoutUser->newSubscription('default', $basicPrice)->checkout([
                'success_url' => route('billing.edit').'?checkout=success',
                'cancel_url' => route('billing.edit').'?checkout=cancelled',
            ]);

            $url = $checkout->asStripeCheckoutSession()->url ?? null;
            $this->assertTrue(is_string($url) && str_contains($url, 'checkout.stripe.com'), 'checkout url');
            $this->line('  checkout session OK');

            if (! $this->option('keep')) {
                $this->info('Cleaning up...');
                $user->subscription('default')?->cancelNow();
                if ($user->hasStripeId()) {
                    $stripe->customers->delete($user->stripe_id);
                }
                $user->delete();

                if ($checkoutUser->hasStripeId()) {
                    $stripe->customers->delete($checkoutUser->stripe_id);
                }
                $checkoutUser->delete();
                $this->line('  cleanup OK');
            } else {
                $this->warn('Kept smoke users and Stripe customers (--keep).');
            }

            $this->info('Billing smoke passed.');

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            if ($user !== null && ! $this->option('keep')) {
                try {
                    $user->subscription('default')?->cancelNow();
                    if ($user->hasStripeId()) {
                        Cashier::stripe()->customers->delete($user->stripe_id);
                    }
                    $user->delete();
                } catch (Throwable) {
                    //
                }
            }

            return self::FAILURE;
        }
    }

    private function assertSame(mixed $expected, mixed $actual, string $label): void
    {
        if ($expected !== $actual) {
            throw new \RuntimeException("{$label}: expected ".var_export($expected, true).', got '.var_export($actual, true));
        }
    }

    private function assertTrue(bool $condition, string $label): void
    {
        if (! $condition) {
            throw new \RuntimeException("{$label}: expected true");
        }
    }

    private function makeSmokeUser(string $email, mixed $trialEndsAt): User
    {
        $user = User::query()->create([
            'name' => 'Billing Smoke',
            'email' => $email,
            'workos_id' => 'smoke_'.uniqid(),
            'avatar' => '',
        ]);

        $user->forceFill([
            'trial_ends_at' => $trialEndsAt,
        ])->save();

        return $user->refresh();
    }
}
