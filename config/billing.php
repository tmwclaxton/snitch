<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Platform subscription
    |--------------------------------------------------------------------------
    */

    'platform_fee_pence' => (int) env('SNITCH_PLATFORM_FEE_PENCE', 1900),
    'platform_stripe_price' => env('STRIPE_PRICE_PLATFORM'),
    'subscription_type' => env('SNITCH_SUBSCRIPTION_TYPE', 'default'),

    /*
    | Usage credits granted when a platform subscription invoice is paid
    | (first subscribe + each renewal). Idempotent per Stripe invoice id.
    */
    'subscription_bonus_pence' => (int) env('SNITCH_SUBSCRIPTION_BONUS_PENCE', 3000),

    /*
    | Claim/confirm bonus (GBP pence). Agents / unclaimed MCP accounts get £0.
    */
    'claim_bonus_pence' => (int) env('SNITCH_CLAIM_BONUS_PENCE', 500),

    /*
    | Billable runs and product data access require balance strictly greater
    | than this (GBP pence). At or below: blocked (same as monthly overage).
    |
    | Free starter: claim_bonus (£5) may be spent without a platform plan.
    | Once that starter allowance is exhausted (denormalized on credit_balances),
    | an active paid platform subscription is required. Credit top-ups are only
    | allowed for subscribed users - top-up alone cannot bypass the paywall.
    */
    'min_run_balance_pence' => (int) env('SNITCH_MIN_RUN_BALANCE_PENCE', 20),

    /*
    | Cache TTL for public pricing-page global vendor averages (ledger means).
    */
    'global_averages_cache_seconds' => (int) env('SNITCH_GLOBAL_AVERAGES_CACHE_SECONDS', 300),

    /*
    | Credit lot expiry
    | - claim_bonus (starter £5): never expires (expires_at null)
    | - subscription_bonus: expires at end of the calendar month it was granted
    | - credits.topup: expires this many months after purchase
    */
    'topup_expiry_months' => (int) env('SNITCH_TOPUP_EXPIRY_MONTHS', 3),

    /*
    | Internal price multiplier on vendor COGS (1.3 = 30% over provider).
    | Never surface this ratio or markup language to users / MCP copy.
    */
    'price_multiplier' => (float) env('SNITCH_BILLING_PRICE_MULTIPLIER', 1.3),

    /*
    | Convert vendor USD COGS to GBP before applying the multiplier.
    */
    'usd_to_gbp' => (float) env('SNITCH_USD_TO_GBP', 0.79),

    /*
    |--------------------------------------------------------------------------
    | Credit packs (one-time Stripe Checkout)
    |--------------------------------------------------------------------------
    */

    'credit_packs' => [
        'pack_10' => [
            'name' => '£10 credits',
            'credits_pence' => 1000,
            'price_pence' => 1000,
            'stripe_price' => env('STRIPE_PRICE_CREDITS_10'),
        ],
        'pack_25' => [
            'name' => '£25 credits',
            'credits_pence' => 2500,
            'price_pence' => 2500,
            'stripe_price' => env('STRIPE_PRICE_CREDITS_25'),
        ],
        'pack_50' => [
            'name' => '£50 credits',
            'credits_pence' => 5000,
            'price_pence' => 5000,
            'stripe_price' => env('STRIPE_PRICE_CREDITS_50'),
        ],
        'pack_100' => [
            'name' => '£100 credits',
            'credits_pence' => 10000,
            'price_pence' => 10000,
            'stripe_price' => env('STRIPE_PRICE_CREDITS_100'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Vendor rate cards (USD COGS estimates when measured usage is missing)
    |--------------------------------------------------------------------------
    |
    | Prefer exact upstream usage (Apify usageTotalUsd, NanoGPT tokens, etc.).
    | These floors are COGS stand-ins only when that data is unavailable - not
    | minimum user charges. Charged price is always COGS × price_multiplier
    | rounded half-up to 0.01p (£0.0001). No min_charge / 1p ceil.
    |
    */

    'vendors' => [
        'apify' => [
            'floor_usd' => 0.01,
            'actors' => [
                'instagram' => ['floor_usd' => 0.02],
                'tiktok' => ['floor_usd' => 0.025],
                'facebook' => ['floor_usd' => 0.02],
                'linkedin' => ['floor_usd' => 0.03],
                'linkedin_profile' => ['floor_usd' => 0.03],
                'youtube' => ['floor_usd' => 0.02],
            ],
        ],
        'nanogpt' => [
            // Per 1M tokens (USD), aligned to NanoGPT list pricing ballpark.
            'models' => [
                'deepseek/deepseek-v4-flash' => [
                    'input_per_m_usd' => 0.14,
                    'output_per_m_usd' => 0.28,
                ],
                'qwen3.7-flash' => [
                    'input_per_m_usd' => 0.10,
                    'output_per_m_usd' => 0.40,
                ],
                'text-embedding-3-small' => [
                    'input_per_m_usd' => 0.02,
                    'output_per_m_usd' => 0.0,
                ],
            ],
            // Typical call COGS when token counts are unknown (not a charge floor).
            'floors_usd' => [
                'chat' => 0.0005,
                'video_analysis' => 0.0005,
                'embeddings' => 0.00005,
            ],
        ],
        'firecrawl' => [
            // Hobby-tier-ish ~$0.0032 / credit.
            'usd_per_credit' => 0.0032,
            'scrape_credits' => 1,
            'search_credits_per_10_results' => 2,
        ],
        'tikhub' => [
            // PAYG list pricing from TikHub docs (retuned after snitch:probe-tikhub).
            'floor_usd' => 0.001,
            'endpoints' => [
                'default' => ['floor_usd' => 0.001],
                'instagram' => ['floor_usd' => 0.002],
                'tiktok' => ['floor_usd' => 0.001],
                'youtube' => ['floor_usd' => 0.001],
                'linkedin' => ['floor_usd' => 0.001],
            ],
        ],
    ],

    /*
    | Product actions → default vendor COGS when a run cannot report usage.
    | Explore product fees use fixed_pence (exact user charge, not COGS × markup).
    */
    'actions' => [
        'apify.run' => ['vendor' => 'apify', 'floor_usd' => 0.01],
        'tikhub.run' => ['vendor' => 'tikhub', 'floor_usd' => 0.001],
        'sync.account' => ['vendor' => 'apify', 'floor_usd' => 0.05],
        'analyze.post' => ['vendor' => 'nanogpt', 'floor_usd' => 0.0005],
        'embed.analysis' => ['vendor' => 'nanogpt', 'floor_usd' => 0.00005],
        'influencers.find' => ['vendor' => 'firecrawl', 'floor_usd' => 0.02],
        'competitors.suggest' => ['vendor' => 'firecrawl', 'floor_usd' => 0.02],
        'brand.autofill' => ['vendor' => 'firecrawl', 'floor_usd' => 0.005],
        'influencer.brief' => ['vendor' => 'nanogpt', 'floor_usd' => 0.0005],
        'competitor.brief' => ['vendor' => 'nanogpt', 'floor_usd' => 0.0005],
        'winners.copy' => ['vendor' => 'nanogpt', 'floor_usd' => 0.0005],
        'explore.search' => ['vendor' => 'snitch', 'fixed_pence' => 0.5],
        'explore.view' => ['vendor' => 'snitch', 'fixed_pence' => 0.1],
    ],

];
