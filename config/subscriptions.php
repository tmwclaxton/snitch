<?php

return [

    /*
    | Seat-based Free/Basic/Pro plans are retired. Hybrid billing lives in
    | config/billing.php (platform fee + prepaid usage credits).
    */

    /*
    | Generic (no-card) trial for claimed website users. Unclaimed MCP agent
    | accounts never receive this clock. Stripe Checkout stays without a
    | subscription trial so the first paid invoice still grants plan credits.
    */
    'trial_days' => (int) env('SNITCH_TRIAL_DAYS', 7),

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
