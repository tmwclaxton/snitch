<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'workos' => [
        'client_id' => env('WORKOS_CLIENT_ID'),
        'secret' => env('WORKOS_API_KEY'),
        'redirect_url' => env('WORKOS_REDIRECT_URL'),
    ],

    'postal' => [
        'key' => env('POSTAL_API_KEY'),
        'base_url' => env('POSTAL_BASE_URL', 'https://postal.grantgunner.org'),
    ],

    'google' => [
        'analytics_id' => env('GOOGLE_ANALYTICS_ID', 'G-Y3VFH257B5'),
        'analytics_enabled' => env('GOOGLE_ANALYTICS_ENABLED'),
        'ads_id' => env('GOOGLE_ADS_ID', 'AW-18219075665'),
        'ads_signup_send_to' => env('GOOGLE_ADS_SIGNUP_SEND_TO', 'AW-18219075665/xFpFCIDTldQcENGQxO9D'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Cursor Automations (Executive Brief + in-app Chat)
    |--------------------------------------------------------------------------
    |
    | When webhook URL/token pairs are missing or look like placeholders, Snitch
    | keeps today's existing brief generation and in-app LLM chat unchanged.
    |
    */
    'cursor_automations' => [
        'mcp_url' => env('CURSOR_AUTOMATIONS_MCP_URL', 'https://www.snitchsocial.net/mcp'),
        'token_ttl_minutes' => (int) env('CURSOR_AUTOMATIONS_TOKEN_TTL_MINUTES', 30),
        'trigger_guard_seconds' => (int) env('CURSOR_AUTOMATIONS_TRIGGER_GUARD_SECONDS', 180),
        'brief' => [
            'webhook_url' => env('CURSOR_BRIEF_WEBHOOK_URL'),
            'webhook_token' => env('CURSOR_BRIEF_WEBHOOK_TOKEN'),
        ],
        'chat' => [
            'webhook_url' => env('CURSOR_CHAT_WEBHOOK_URL'),
            'webhook_token' => env('CURSOR_CHAT_WEBHOOK_TOKEN'),
            'timeout_seconds' => (int) env('CURSOR_CHAT_TIMEOUT_SECONDS', 180),
        ],
    ],

];
