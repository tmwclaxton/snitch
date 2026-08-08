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
    | Competitor caps and Stripe Price IDs (recurring GBP monthly). Trial is
    | not a Stripe product; it reuses Basic competitor_limit while active.
    |
    */

    'plans' => [
        'free' => [
            'name' => 'Free',
            'price_pence' => 0,
            'competitor_limit' => 3,
            'stripe_price' => null,
        ],
        'basic' => [
            'name' => 'Basic',
            'price_pence' => 2000,
            'competitor_limit' => 10,
            'stripe_price' => env('STRIPE_PRICE_BASIC'),
        ],
        'pro' => [
            'name' => 'Pro',
            'price_pence' => 9900,
            'competitor_limit' => 50,
            'stripe_price' => env('STRIPE_PRICE_PRO'),
        ],
    ],

    'subscription_type' => 'default',

];
