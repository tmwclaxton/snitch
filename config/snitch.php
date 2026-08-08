<?php

return [

    /*
    | Public support address shown on marketing pages. Contact form deliveries
    | go to contact_to (defaults to the same address when unset).
    */
    'support_email' => env('SNITCH_SUPPORT_EMAIL', 'hello@snitchsocial.net'),
    'contact_to' => env('SNITCH_CONTACT_TO', env('SNITCH_SUPPORT_EMAIL', 'hello@snitchsocial.net')),

    'nanogpt' => [
        'api_key' => env('NANOGPT_API_KEY'),
        'base_url' => rtrim((string) env('NANOGPT_BASE_URL', 'https://nano-gpt.com/api/v1'), '/'),
        'timeout' => (int) env('NANOGPT_TIMEOUT', 180),
    ],

    'firecrawl' => [
        'api_key' => env('FIRECRAWL_API_KEY'),
        'base_url' => rtrim((string) env('FIRECRAWL_BASE_URL', 'https://api.firecrawl.dev/v1'), '/'),
        'timeout' => (int) env('FIRECRAWL_TIMEOUT', 60),
        'connect_timeout' => (int) env('FIRECRAWL_CONNECT_TIMEOUT', 5),
    ],

    'brand_autofill' => [
        'model' => env('SNITCH_BRAND_AUTOFILL_MODEL', 'deepseek/deepseek-v4-flash'),
        'max_tokens' => (int) env('SNITCH_BRAND_AUTOFILL_MAX_TOKENS', 220),
        'temperature' => (float) env('SNITCH_BRAND_AUTOFILL_TEMPERATURE', 0.3),
    ],

    'competitor_suggest' => [
        'model' => env('SNITCH_COMPETITOR_SUGGEST_MODEL', 'deepseek/deepseek-v4-flash'),
        'max_tokens' => (int) env('SNITCH_COMPETITOR_SUGGEST_MAX_TOKENS', 1600),
        'temperature' => (float) env('SNITCH_COMPETITOR_SUGGEST_TEMPERATURE', 0.3),
        // Target orgs from Firecrawl+LLM before Apify resolve.
        'max_candidates' => (int) env('SNITCH_COMPETITOR_SUGGEST_MAX_CANDIDATES', 16),
        'max_resolves' => (int) env('SNITCH_COMPETITOR_SUGGEST_MAX_RESOLVES', 32),
        'max_suggestions' => (int) env('SNITCH_COMPETITOR_SUGGEST_MAX_SUGGESTIONS', 16),
        'min_suggestions' => (int) env('SNITCH_COMPETITOR_SUGGEST_MIN_SUGGESTIONS', 6),
        'search_limit' => (int) env('SNITCH_COMPETITOR_SUGGEST_SEARCH_LIMIT', 8),
        // Concurrent Apify resolveProfile calls per verify batch (Http::pool).
        'resolve_concurrency' => (int) env('SNITCH_COMPETITOR_SUGGEST_RESOLVE_CONCURRENCY', 4),
        // Soft cap during verify so Facebook cannot starve other platforms when they resolve.
        'max_per_platform' => (int) env('SNITCH_COMPETITOR_SUGGEST_MAX_PER_PLATFORM', 3),
        // Multi-platform mix; Apify still must return external_id to keep a row.
        'platforms' => ['instagram', 'tiktok', 'youtube', 'linkedin', 'facebook'],
    ],

    /*
    | Cost-disciplined sync: only import posts from the last N days, with a modest
    | per-account fetch cap. Analyze jobs also respect this recency window.
    | min_interval_days skips re-sync when the account was successfully synced
    | recently (scheduled + manual Show sync); failed syncs remain eligible.
    */
    'sync' => [
        'recency_days' => (int) env('SNITCH_SYNC_RECENCY_DAYS', 30),
        'posts_limit' => (int) env('SNITCH_SYNC_POSTS_LIMIT', 12),
        'min_interval_days' => (int) env('SNITCH_SYNC_MIN_INTERVAL_DAYS', 7),
        // Over-fetch raw actor items so reel-only mapping can still fill posts_limit.
        // TikTok is mostly video already; Instagram needs more headroom for carousels.
        'fetch_multipliers' => [
            'instagram' => 2.5,
            'facebook' => 2.0,
            'linkedin' => 2.0,
            'tiktok' => 1.25,
            'youtube' => 1.0,
        ],
    ],

    'video_analysis' => [
        'model' => env('SNITCH_VIDEO_ANALYSIS_MODEL', 'qwen3.7-flash'),
        'max_tokens' => (int) env('SNITCH_VIDEO_ANALYSIS_MAX_TOKENS', 1800),
        'temperature' => (float) env('SNITCH_VIDEO_ANALYSIS_TEMPERATURE', 0.2),
        'success' => [
            'min_hook_chars' => 12,
            'min_hook_window_end_seconds' => 3.0,
            'min_visual_summary_chars' => 40,
            'min_idea_chars' => 12,
            'min_concept_chars' => 12,
            'require_sfx_array' => true,
            'require_sfx_labels_when_present' => true,
            'require_cta_field' => true,
            'require_how_to_copy_chars' => 20,
            'max_caption_overlap_ratio' => 0.65,
        ],
    ],

    'winners' => [
        'copy_model' => env('SNITCH_WINNER_COPY_MODEL', 'deepseek/deepseek-v4-flash'),
        'presets' => [
            'conservative' => [
                'min_engagement_rate' => 5,
                'min_views' => 5000,
                'min_likes' => 500,
                'recency_days' => 14,
                'weights' => [
                    'views' => 0.35,
                    'likes' => 0.35,
                    'comments' => 0.2,
                    'shares' => 0.1,
                ],
            ],
            'balanced' => [
                'min_engagement_rate' => 3,
                'min_views' => 1000,
                'min_likes' => 100,
                'recency_days' => 30,
                'weights' => [
                    'views' => 0.4,
                    'likes' => 0.3,
                    'comments' => 0.2,
                    'shares' => 0.1,
                ],
            ],
            'aggressive' => [
                'min_engagement_rate' => 1,
                'min_views' => 200,
                'min_likes' => 20,
                'recency_days' => 60,
                'weights' => [
                    'views' => 0.45,
                    'likes' => 0.25,
                    'comments' => 0.2,
                    'shares' => 0.1,
                ],
            ],
        ],
    ],

    'apify' => [
        'token' => env('APIFY_TOKEN'),
        'base_url' => rtrim((string) env('APIFY_BASE_URL', 'https://api.apify.com/v2'), '/'),
        'timeout' => (int) env('APIFY_TIMEOUT', 180),
        'actors' => [
            'instagram' => env('APIFY_ACTOR_INSTAGRAM', 'apify/instagram-scraper'),
            'tiktok' => env('APIFY_ACTOR_TIKTOK', 'clockworks/tiktok-scraper'),
            'facebook' => env('APIFY_ACTOR_FACEBOOK', 'apify/facebook-posts-scraper'),
            // Brand competitor pages (default sync + company suggest resolves).
            'linkedin' => env('APIFY_ACTOR_LINKEDIN', 'apimaestro/linkedin-company-posts'),
            // Personal creator profiles (/in/...) for suggest verify.
            'linkedin_profile' => env('APIFY_ACTOR_LINKEDIN_PROFILE', 'apimaestro/linkedin-profile-posts'),
            // Shorts-only import policy via maxResultsShorts; skip long-form uploads.
            'youtube' => env('APIFY_ACTOR_YOUTUBE', 'streamers/youtube-scraper'),
        ],
    ],

];
