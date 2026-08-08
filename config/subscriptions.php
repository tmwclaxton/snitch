<?php

return [

    /*
    |--------------------------------------------------------------------------
    | App trial (no card)
    |--------------------------------------------------------------------------
    |
    | New users get Basic entitlements until trial_ends_at without a Stripe
    | subscription. After expiry with no active plan they fall back to Free.
    |
    */

    'trial_days' => (int) env('SNITCH_TRIAL_DAYS', 7),

    /*
    |--------------------------------------------------------------------------
    | Plans
    |--------------------------------------------------------------------------
    |
    | Competitor caps and Stripe Price IDs (recurring GBP). Yearly is 20% off
    | the monthly total (monthly price_pence * 12 * 0.8). Trial is not a Stripe
    | product; it reuses Basic competitor_limit while active.
    |
    */

    'plans' => [
        'free' => [
            'name' => 'Free',
            'price_pence' => 0,
            'yearly_price_pence' => 0,
            'competitor_limit' => 3,
            'stripe_price' => null,
            'stripe_price_yearly' => null,
        ],
        'basic' => [
            'name' => 'Basic',
            'price_pence' => 2000,
            'yearly_price_pence' => 19200,
            'competitor_limit' => 10,
            'stripe_price' => env('STRIPE_PRICE_BASIC'),
            'stripe_price_yearly' => env('STRIPE_PRICE_BASIC_YEARLY'),
        ],
        'pro' => [
            'name' => 'Pro',
            'price_pence' => 9900,
            'yearly_price_pence' => 95040,
            'competitor_limit' => 50,
            'stripe_price' => env('STRIPE_PRICE_PRO'),
            'stripe_price_yearly' => env('STRIPE_PRICE_PRO_YEARLY'),
        ],
    ],

    'subscription_type' => 'default',

    'yearly_discount_percent' => 20,

];
