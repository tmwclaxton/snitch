<?php

return [

    /*
    | Seat-based Free/Basic/Pro plans are retired. Hybrid billing lives in
    | config/billing.php (platform fee + prepaid usage credits).
    */

    /*
    | Stripe Checkout trial length for the £19 platform price. Card is taken
    | at checkout via subscription_data.trial_period_days. Set 0 to disable.
    | Claimed website users still get starter credit; product access requires
    | an active or trialing Cashier subscription (admins and user id 1 bypass).
    */
    'trial_days' => (int) env('SNITCH_TRIAL_DAYS', 7),

    /*
    | Competitors included while the Stripe subscription is trialing (or
    | before checkout during onboarding). Null / omit = unlimited after trial.
    */
    'trial_competitor_limit' => (int) env('SNITCH_TRIAL_COMPETITOR_LIMIT', 3),

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
