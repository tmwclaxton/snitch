<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Laravel\Cashier\Cashier;
use Stripe\Exception\ApiErrorException;

#[Signature('snitch:stripe-sync-plans {--dry-run : Print planned products without calling Stripe}')]
#[Description('Create Stripe Products/Prices for Basic and Pro (monthly + yearly) and print Price IDs for .env')]
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
                    '[dry-run] %s: %s (%d pence / month, %d pence / year, 20%% off)',
                    $key,
                    $plan['name'] ?? $key,
                    (int) ($plan['price_pence'] ?? 0),
                    (int) ($plan['yearly_price_pence'] ?? 0),
                ));
            }

            return self::SUCCESS;
        }

        $stripe = Cashier::stripe();
        $printed = [];

        foreach ($plans as $key => $plan) {
            $name = (string) ($plan['name'] ?? ucfirst($key));
            $monthlyAmount = (int) ($plan['price_pence'] ?? 0);
            $yearlyAmount = (int) ($plan['yearly_price_pence'] ?? 0);
            $existingMonthly = filled($plan['stripe_price'] ?? null) ? (string) $plan['stripe_price'] : null;
            $existingYearly = filled($plan['stripe_price_yearly'] ?? null) ? (string) $plan['stripe_price_yearly'] : null;

            try {
                $productId = null;
                $monthlyPriceId = $existingMonthly;
                $yearlyPriceId = $existingYearly;

                if (filled($existingMonthly)) {
                    $existing = $stripe->prices->retrieve($existingMonthly);
                    $productId = is_string($existing->product) ? $existing->product : $existing->product->id;
                    $this->line("Reusing {$name} product={$productId} monthly={$existingMonthly}");
                } else {
                    $product = $stripe->products->create([
                        'name' => "Snitch {$name}",
                        // Required when Stripe Managed Payments / tax is enabled on the account.
                        'tax_code' => 'txcd_10000000',
                        'metadata' => [
                            'snitch_plan' => $key,
                        ],
                    ]);
                    $productId = $product->id;

                    $monthly = $stripe->prices->create([
                        'product' => $productId,
                        'unit_amount' => $monthlyAmount,
                        'currency' => 'gbp',
                        'recurring' => [
                            'interval' => 'month',
                        ],
                        'metadata' => [
                            'snitch_plan' => $key,
                            'snitch_interval' => 'month',
                        ],
                    ]);
                    $monthlyPriceId = $monthly->id;
                    $this->info("Created {$name} monthly: product={$productId} price={$monthlyPriceId}");
                }

                if (! filled($existingYearly)) {
                    $yearly = $stripe->prices->create([
                        'product' => $productId,
                        'unit_amount' => $yearlyAmount,
                        'currency' => 'gbp',
                        'recurring' => [
                            'interval' => 'year',
                        ],
                        'metadata' => [
                            'snitch_plan' => $key,
                            'snitch_interval' => 'year',
                        ],
                    ]);
                    $yearlyPriceId = $yearly->id;
                    $this->info("Created {$name} yearly: price={$yearlyPriceId}");
                } else {
                    $this->line("Reusing {$name} yearly={$existingYearly}");
                }
            } catch (ApiErrorException $e) {
                $this->error("Stripe API error for {$key}: {$e->getMessage()}");

                return self::FAILURE;
            }

            $monthlyEnv = $key === 'basic' ? 'STRIPE_PRICE_BASIC' : 'STRIPE_PRICE_PRO';
            $yearlyEnv = $key === 'basic' ? 'STRIPE_PRICE_BASIC_YEARLY' : 'STRIPE_PRICE_PRO_YEARLY';
            $printed[$monthlyEnv] = $monthlyPriceId;
            $printed[$yearlyEnv] = $yearlyPriceId;
        }

        $this->newLine();
        $this->line('Add these to your .env (and production env):');
        foreach ($printed as $envKey => $priceId) {
            $this->line("{$envKey}={$priceId}");
        }

        return self::SUCCESS;
    }
}
