<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Laravel\Cashier\Cashier;
use Stripe\Exception\ApiErrorException;

#[Signature('snitch:stripe-sync-plans {--dry-run : Print planned products without calling Stripe}')]
#[Description('Create Stripe Products/Prices for platform fee + credit packs and print Price IDs for .env')]
class StripeSyncPlansCommand extends Command
{
    public function handle(): int
    {
        $secret = config('cashier.secret');

        if (! is_string($secret) || $secret === '') {
            $this->error('STRIPE_SECRET is not set. Add it to .env before syncing plans.');

            return self::FAILURE;
        }

        $platformFee = (int) config('billing.platform_fee_pence', 1900);
        $packs = config('billing.credit_packs', []);

        if ($this->option('dry-run')) {
            $this->line(sprintf('[dry-run] platform: Snitch Platform (%d pence / month)', $platformFee));

            foreach ($packs as $key => $pack) {
                $this->line(sprintf(
                    '[dry-run] %s: %s (%d pence one-time)',
                    $key,
                    $pack['name'] ?? $key,
                    (int) ($pack['price_pence'] ?? 0),
                ));
            }

            return self::SUCCESS;
        }

        $stripe = Cashier::stripe();
        $printed = [];

        try {
            $existingPlatform = filled(config('billing.platform_stripe_price'))
                ? (string) config('billing.platform_stripe_price')
                : null;

            if ($existingPlatform) {
                $printed['STRIPE_PRICE_PLATFORM'] = $existingPlatform;
                $this->line("Reusing platform price={$existingPlatform}");
            } else {
                $product = $stripe->products->create([
                    'name' => 'Snitch Platform',
                    'tax_code' => 'txcd_10000000',
                    'metadata' => ['snitch_product' => 'platform'],
                ]);

                $price = $stripe->prices->create([
                    'product' => $product->id,
                    'unit_amount' => $platformFee,
                    'currency' => 'gbp',
                    'recurring' => ['interval' => 'month'],
                    'metadata' => ['snitch_product' => 'platform'],
                ]);

                $printed['STRIPE_PRICE_PLATFORM'] = $price->id;
                $this->info("Created platform product={$product->id} price={$price->id}");
            }

            foreach ($packs as $key => $pack) {
                $envKey = 'STRIPE_PRICE_CREDITS_'.strtoupper(str_replace('pack_', '', (string) $key));
                $existing = filled($pack['stripe_price'] ?? null) ? (string) $pack['stripe_price'] : null;

                if ($existing) {
                    $printed[$envKey] = $existing;
                    $this->line("Reusing {$key} price={$existing}");

                    continue;
                }

                $product = $stripe->products->create([
                    'name' => (string) ($pack['name'] ?? 'Snitch credits'),
                    'tax_code' => 'txcd_10000000',
                    'metadata' => [
                        'snitch_product' => 'credits',
                        'credit_pack' => (string) $key,
                    ],
                ]);

                $price = $stripe->prices->create([
                    'product' => $product->id,
                    'unit_amount' => (int) ($pack['price_pence'] ?? 0),
                    'currency' => 'gbp',
                    'metadata' => [
                        'snitch_product' => 'credits',
                        'credit_pack' => (string) $key,
                        'credits_pence' => (string) ((int) ($pack['credits_pence'] ?? 0)),
                    ],
                ]);

                $printed[$envKey] = $price->id;
                $this->info("Created {$key} product={$product->id} price={$price->id}");
            }
        } catch (ApiErrorException $e) {
            $this->error('Stripe API error: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->newLine();
        $this->info('Add these to your .env:');

        foreach ($printed as $env => $id) {
            $this->line("{$env}={$id}");
        }

        return self::SUCCESS;
    }
}
