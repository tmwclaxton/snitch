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

    /*
    | Reel analysis embeddings (NanoGPT /embeddings) power Explore semantic
    | search for custom_tag clicks and free-text q. Catalogue slug filters stay exact.
    */
    'embeddings' => [
        'enabled' => (bool) env('SNITCH_EMBEDDINGS_ENABLED', true),
        'model' => env('NANOGPT_EMBEDDING_MODEL', 'text-embedding-3-small'),
        // 0 keeps the model default size.
        'dimensions' => (int) env('NANOGPT_EMBEDDING_DIMENSIONS', 0),
        'min_similarity' => (float) env('SNITCH_EMBEDDING_MIN_SIMILARITY', 0.22),
        // Cap candidates scored in PHP per Explore request.
        'max_candidates' => (int) env('SNITCH_EMBEDDING_MAX_CANDIDATES', 500),
    ],

    /*
    | Explore mix: quality-biased shuffle so the catalogue rotates among strong
    | reels instead of always leading with the same top score, without surfacing
    | weak junk on page one. Bare /explore mints a new seed each visit; any query
    | reuses explore_seed or falls back to the hour bucket for stable filters/pages.
    */
    'explore' => [
        'mix_enabled' => filter_var(env('SNITCH_EXPLORE_MIX_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
        // Posts below (max_score * ratio) trail the mixed strong set.
        'min_quality_ratio' => (float) env('SNITCH_EXPLORE_MIN_QUALITY_RATIO', 0.35),
        // Higher = greedier toward top scores within the strong set.
        'weight_exponent' => (float) env('SNITCH_EXPLORE_WEIGHT_EXPONENT', 1.5),
        // 0 = near-pure score order; 1 = more rotation among strong peers.
        'jitter' => (float) env('SNITCH_EXPLORE_JITTER', 0.65),
        // Used when the request has query params but no explore_seed.
        'seed_bucket_hours' => (int) env('SNITCH_EXPLORE_SEED_BUCKET_HOURS', 6),
        'max_candidates' => (int) env('SNITCH_EXPLORE_MAX_CANDIDATES', 500),
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

    'influencer_find' => [
        'model' => env('SNITCH_INFLUENCER_FIND_MODEL', 'deepseek/deepseek-v4-flash'),
        'max_tokens' => (int) env('SNITCH_INFLUENCER_FIND_MAX_TOKENS', 1600),
        'temperature' => (float) env('SNITCH_INFLUENCER_FIND_TEMPERATURE', 0.3),
        'brief_max_tokens' => (int) env('SNITCH_INFLUENCER_FIND_BRIEF_MAX_TOKENS', 280),
        'brief_temperature' => (float) env('SNITCH_INFLUENCER_FIND_BRIEF_TEMPERATURE', 0.4),
        'max_candidates' => (int) env('SNITCH_INFLUENCER_FIND_MAX_CANDIDATES', 20),
        'max_resolves' => (int) env('SNITCH_INFLUENCER_FIND_MAX_RESOLVES', 40),
        'max_suggestions' => (int) env('SNITCH_INFLUENCER_FIND_MAX_SUGGESTIONS', 10),
        'min_suggestions' => (int) env('SNITCH_INFLUENCER_FIND_MIN_SUGGESTIONS', 6),
        'search_limit' => (int) env('SNITCH_INFLUENCER_FIND_SEARCH_LIMIT', 12),
        'model_seed_count' => (int) env('SNITCH_INFLUENCER_FIND_MODEL_SEED_COUNT', 12),
        'apify_search_limit' => (int) env('SNITCH_INFLUENCER_FIND_APIFY_SEARCH_LIMIT', 15),
        'resolve_concurrency' => (int) env('SNITCH_INFLUENCER_FIND_RESOLVE_CONCURRENCY', 4),
        'max_per_platform' => (int) env('SNITCH_INFLUENCER_FIND_MAX_PER_PLATFORM', 5),
        'seeds' => [
            'model' => filter_var(env('SNITCH_INFLUENCER_FIND_SEED_MODEL', true), FILTER_VALIDATE_BOOLEAN),
            'firecrawl' => filter_var(env('SNITCH_INFLUENCER_FIND_SEED_FIRECRAWL', true), FILTER_VALIDATE_BOOLEAN),
            'apify_search' => filter_var(env('SNITCH_INFLUENCER_FIND_SEED_APIFY_SEARCH', true), FILTER_VALIDATE_BOOLEAN),
        ],
        'platforms' => ['instagram', 'tiktok', 'youtube', 'linkedin', 'facebook'],
        'default_platform' => 'instagram',
        'default_language' => 'English',
        'default_min_followers' => (int) env('SNITCH_INFLUENCER_FIND_DEFAULT_MIN_FOLLOWERS', 1000),
        'default_max_followers' => (int) env('SNITCH_INFLUENCER_FIND_DEFAULT_MAX_FOLLOWERS', 50000),
    ],

    /*
    | Cost-disciplined sync: only import posts from the last N days, with a modest
    | per-account fetch cap. Analyse jobs also respect this recency window.
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

    /*
    | Music recognition: platform metadata (in PostAnalysis.music via
    | PlatformMusicExtractor) beats provider lookups. When platform music is
    | absent, MusicRecognitionService tries AcoustID (with a chromaprint
    | fingerprint from fpcalc) and then AudD as a short-clip fallback. A missing
    | key or binary degrades that step gracefully so analysis keeps working.
    */
    'music_recognition' => [
        'enabled' => filter_var(env('SNITCH_MUSIC_RECOGNITION_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
        // Cache recognized tracks by media hash so re-analysis / duplicates skip vendor calls.
        'cache_ttl_seconds' => (int) env('SNITCH_MUSIC_RECOGNITION_CACHE_TTL', 60 * 60 * 24 * 30),
        // Minimum provider score to accept (AcoustID 0..1; AudD 0..100 normalized).
        'min_confidence' => (float) env('SNITCH_MUSIC_RECOGNITION_MIN_CONFIDENCE', 0.55),
        // Seconds of audio to extract for AudD (clip cost matters, keep <= 20s).
        'clip_seconds' => (int) env('SNITCH_MUSIC_RECOGNITION_CLIP_SECONDS', 12),
        // Skip recognition when the extracted clip mean volume falls below this
        // (rough silence gate; keeps AudD credits from burning on silent clips).
        'silence_dbfs' => (float) env('SNITCH_MUSIC_RECOGNITION_SILENCE_DBFS', -45.0),
        'fpcalc_binary' => env('SNITCH_FPCALC_BINARY', 'fpcalc'),
        'ffmpeg_binary' => env('SNITCH_FFMPEG_BINARY', 'ffmpeg'),
        'acoustid' => [
            'api_key' => env('ACOUSTID_API_KEY'),
            'base_url' => rtrim((string) env('ACOUSTID_BASE_URL', 'https://api.acoustid.org/v2'), '/'),
            'timeout' => (int) env('ACOUSTID_TIMEOUT', 20),
        ],
        'audd' => [
            'api_key' => env('AUDD_API_KEY'),
            'base_url' => rtrim((string) env('AUDD_BASE_URL', 'https://api.audd.io'), '/'),
            'timeout' => (int) env('AUDD_TIMEOUT', 30),
            // Approximate provider COGS per successful recognition (USD).
            'cogs_usd' => (float) env('AUDD_COGS_USD', 0.005),
        ],
        // Spotify link enrichment: AudD already returns a Spotify id for free.
        // When absent (AcoustID hits, platform metadata), we optionally use
        // Firecrawl to resolve title+artist -> Spotify track URL. Cached, safe to
        // disable when Firecrawl is not configured.
        'spotify_resolver' => [
            'enabled' => filter_var(env('SNITCH_SPOTIFY_RESOLVER_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
            'cache_ttl_seconds' => (int) env('SNITCH_SPOTIFY_RESOLVER_CACHE_TTL', 60 * 60 * 24 * 30),
            'search_limit' => (int) env('SNITCH_SPOTIFY_RESOLVER_SEARCH_LIMIT', 5),
        ],
    ],

    'video_analysis' => [
        'model' => env('SNITCH_VIDEO_ANALYSIS_MODEL', 'qwen3.7-flash'),
        'max_tokens' => (int) env('SNITCH_VIDEO_ANALYSIS_MAX_TOKENS', 1800),
        'temperature' => (float) env('SNITCH_VIDEO_ANALYSIS_TEMPERATURE', 0.2),
        // NanoGPT rejects request bodies over ~4.4MB when media is inlined as base64.
        'max_inline_data_uri_bytes' => (int) env('SNITCH_VIDEO_ANALYSIS_MAX_INLINE_DATA_URI_BYTES', 4_200_000),
        'ffmpeg_binary' => env('SNITCH_FFMPEG_BINARY', 'ffmpeg'),
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
        // Soft monthly COGS cap (USD). At/over this + TikHub key set → TikHub for IG/TT/YT/LI.
        'monthly_cap_usd' => (float) env('SNITCH_APIFY_MONTHLY_CAP_USD', 49),
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

    'tikhub' => [
        'api_key' => env('TIKHUB_API_KEY'),
        'base_url' => rtrim((string) env('TIKHUB_BASE_URL', 'https://api.tikhub.io'), '/'),
        'timeout' => (int) env('TIKHUB_TIMEOUT', 60),
        'endpoints' => [
            'instagram' => [
                'user_info' => '/api/v1/instagram/v2/fetch_user_info',
                'user_posts' => '/api/v1/instagram/v2/fetch_user_posts',
                'user_reels' => '/api/v1/instagram/v2/fetch_user_reels',
                'search_users' => '/api/v1/instagram/v2/search_users',
            ],
            'tiktok' => [
                'user_profile' => '/api/v1/tiktok/web/fetch_user_profile',
                'user_posts' => '/api/v1/tiktok/app/v3/fetch_user_post_videos',
                'one_video' => '/api/v1/tiktok/app/v3/fetch_one_video',
                'search_users' => '/api/v1/tiktok/app/v3/fetch_user_search',
            ],
            'youtube' => [
                'channel_info' => '/api/v1/youtube/web/get_channel_id',
                'channel_videos' => '/api/v1/youtube/web/get_channel_videos_v2',
                'channel_shorts' => '/api/v1/youtube/web/get_channel_short_videos',
                // Player/stream payload (MP4 URLs). Often lacks publish dates.
                'video_info' => '/api/v1/youtube/web/get_video_info_v2',
                // Formatted metadata; carries date_text when publish_date is empty.
                'video_metadata' => '/api/v1/youtube/web_v2/get_video_info',
                'search' => '/api/v1/youtube/web/search_channel',
            ],
            'linkedin' => [
                'company_posts' => '/api/v1/linkedin/web_v2/get_company_posts',
                'profile_posts' => '/api/v1/linkedin/web_v2/get_user_posts',
                'company_info' => '/api/v1/linkedin/web_v2/get_company_profile',
            ],
        ],
    ],

];
