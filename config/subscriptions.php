<?php

return [

    /*
    | Seat-based Free/Basic/Pro plans are retired. Hybrid billing lives in
    | config/billing.php (platform fee + prepaid usage credits).
    */

    'trial_days' => 0,

    'plans' => [
        'none' => [
            'name' => 'No plan',
            'price_pence' => 0,
            'yearly_price_pence' => 0,
            'competitor_limit' => null,
            'influencer_limit' => null,
            'stripe_price' => null,
            'stripe_price_yearly' => null,
        ],
        'platform' => [
            'name' => 'Platform',
            'price_pence' => (int) env('SNITCH_PLATFORM_FEE_PENCE', 1900),
            'yearly_price_pence' => 0,
            'competitor_limit' => null,
            'influencer_limit' => null,
            'stripe_price' => env('STRIPE_PRICE_PLATFORM'),
            'stripe_price_yearly' => null,
        ],
    ],

    'subscription_type' => env('SNITCH_SUBSCRIPTION_TYPE', 'default'),

    'yearly_discount_percent' => 0,

];
