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
#[Description('Smoke-test Stripe platform price, Checkout, and subscription against the configured keys')]
class BillingSmokeCommand extends Command
{
    public function handle(PlanEntitlementService $entitlements): int
    {
        $secret = (string) config('cashier.secret');
        $platformPrice = (string) config('billing.platform_stripe_price');

        if ($secret === '' || ! str_starts_with($secret, 'sk_test_')) {
            $this->error('Billing smoke requires STRIPE_SECRET to be an sk_test_ key.');

            return self::FAILURE;
        }

        if ($platformPrice === '') {
            $this->error('STRIPE_PRICE_PLATFORM must be set.');

            return self::FAILURE;
        }

        $user = null;

        try {
            $stripe = Cashier::stripe();

            $this->info('Checking platform price...');
            $platform = $stripe->prices->retrieve($platformPrice);
            $this->assertSame('gbp', $platform->currency, 'Platform currency');
            $this->assertSame((int) config('billing.platform_fee_pence', 1900), $platform->unit_amount, 'Platform amount');
            $this->assertSame('month', $platform->recurring?->interval, 'Platform interval');
            $this->line('  catalog OK');

            $user = $this->makeSmokeUser(
                email: 'billing-smoke+'.uniqid().'@snitchsocial.net',
            );

            $this->info("Created smoke user #{$user->id}");

            $this->info('Creating platform subscription with pm_card_visa...');
            $user->newSubscription('default', $platformPrice)->create('pm_card_visa');
            $user->refresh();

            $this->assertTrue($user->subscribed('default'), 'subscribed platform');
            $this->assertSame('platform', $entitlements->plan($user), 'plan platform');
            $this->assertTrue($entitlements->hasPlatformSubscription($user), 'has platform');
            $this->line('  platform subscription OK');

            $this->info('Creating Checkout session...');
            $checkoutUser = $this->makeSmokeUser(
                email: 'billing-checkout+'.uniqid().'@snitchsocial.net',
            );

            $checkout = $checkoutUser->newSubscription('default', $platformPrice)->checkout([
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
            report($e);
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }

    private function makeSmokeUser(string $email): User
    {
        $user = User::factory()->create([
            'email' => $email,
            'workos_id' => 'smoke_'.uniqid(),
            'claimed_at' => now(),
            'created_via' => 'web',
        ]);
        $user->createAsStripeCustomer();

        return $user;
    }

    private function assertSame(mixed $expected, mixed $actual, string $label): void
    {
        if ($expected !== $actual) {
            throw new \RuntimeException("Assertion failed [{$label}]: expected ".json_encode($expected).', got '.json_encode($actual));
        }
    }

    private function assertTrue(bool $actual, string $label): void
    {
        if (! $actual) {
            throw new \RuntimeException("Assertion failed [{$label}]: expected true");
        }
    }
}
