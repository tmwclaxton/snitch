<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Laravel\Cashier\Cashier;
use Stripe\Exception\ApiErrorException;

#[Signature('snitch:stripe-sync-plans {--dry-run : Print planned products without calling Stripe}')]
#[Description('Create Stripe Products/Prices for Basic and Pro and print Price IDs for .env')]
class StripeSyncPlansCommand extends Command
{
    public function handle(): int
    {
        $secret = config('cashier.secret');

        if (! is_string($secret) || $secret === '') {
            $this->error('STRIPE_SECRET is not set. Add it to .env before syncing plans.');

            return self::FAILURE;
        }

        $plans = [
            'basic' => config('subscriptions.plans.basic'),
            'pro' => config('subscriptions.plans.pro'),
        ];

        if ($this->option('dry-run')) {
            foreach ($plans as $key => $plan) {
                $this->line(sprintf(
                    '[dry-run] %s: %s (%d pence GBP / month)',
                    $key,
                    $plan['name'] ?? $key,
                    (int) ($plan['price_pence'] ?? 0),
                ));
            }

            return self::SUCCESS;
        }

        $stripe = Cashier::stripe();
        $printed = [];

        foreach ($plans as $key => $plan) {
            $name = (string) ($plan['name'] ?? ucfirst($key));
            $amount = (int) ($plan['price_pence'] ?? 0);

            try {
                $product = $stripe->products->create([
                    'name' => "Snitch {$name}",
                    'metadata' => [
                        'snitch_plan' => $key,
                    ],
                ]);

                $price = $stripe->prices->create([
                    'product' => $product->id,
                    'unit_amount' => $amount,
                    'currency' => 'gbp',
                    'recurring' => [
                        'interval' => 'month',
                    ],
                    'metadata' => [
                        'snitch_plan' => $key,
                    ],
                ]);
            } catch (ApiErrorException $e) {
                $this->error("Stripe API error for {$key}: {$e->getMessage()}");

                return self::FAILURE;
            }

            $envKey = $key === 'basic' ? 'STRIPE_PRICE_BASIC' : 'STRIPE_PRICE_PRO';
            $printed[$envKey] = $price->id;

            $this->info("Created {$name}: product={$product->id} price={$price->id}");
        }

        $this->newLine();
        $this->line('Add these to your .env (and production env):');
        foreach ($printed as $envKey => $priceId) {
            $this->line("{$envKey}={$priceId}");
        }

        return self::SUCCESS;
    }
}
