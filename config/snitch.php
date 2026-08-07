<?php

return [

    'nanogpt' => [
        'api_key' => env('NANOGPT_API_KEY'),
        'base_url' => rtrim((string) env('NANOGPT_BASE_URL', 'https://nano-gpt.com/api/v1'), '/'),
        'timeout' => (int) env('NANOGPT_TIMEOUT', 180),
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
            'require_sfx_array' => true,
            'require_sfx_labels_when_present' => true,
            'require_cta_field' => true,
            'require_how_to_copy_chars' => 20,
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
            'linkedin' => env('APIFY_ACTOR_LINKEDIN', 'apimaestro/linkedin-profile-posts'),
            'pinterest' => env('APIFY_ACTOR_PINTEREST', 'easyapi/pinterest-search-scraper'),
        ],
    ],

];
