<?php

return [

    /*
    | Public marketing origin for links inside generated posts.
    | Kept separate from APP_URL so local never writes localhost into content.
    */
    'public_site_url' => env('BLOG_PUBLIC_SITE_URL', 'https://www.snitchsocial.net'),

    'hero_image_disk' => env('BLOG_HERO_IMAGE_DISK', 'public'),

    'hero_image_path_prefix' => 'blogs/heroes',

    /*
    | Default status for blog:generate when creating new posts.
    | Use draft until spot-check habit is solid, then published or blog:publish.
    */
    'default_generate_status' => env('BLOG_DEFAULT_GENERATE_STATUS', 'draft'),

    'generate' => [
        'max_attempts_per_step' => 3,
        'plan_timeout_seconds' => 90,
        'section_timeout_seconds' => 120,
        'firecrawl_search_limit' => 8,
        'model' => env('BLOG_GENERATE_MODEL', env('SNITCH_VIDEO_ANALYSIS_MODEL', 'qwen3.7-flash')),
        'max_tokens' => (int) env('BLOG_GENERATE_MAX_TOKENS', 2200),
        'temperature' => (float) env('BLOG_GENERATE_TEMPERATURE', 0.4),
    ],

    'image' => [
        'base_url' => rtrim((string) env('NANOGPT_IMAGE_BASE_URL', env('NANOGPT_BASE_URL', 'https://nano-gpt.com/api/v1')), '/'),
        'model' => env('BLOG_IMAGE_MODEL', 'flux-schnell'),
        'size' => env('BLOG_IMAGE_SIZE', '1792x1024'),
        'timeout' => (int) env('BLOG_IMAGE_TIMEOUT', 120),
    ],

    'lengths' => [
        'short' => ['sections' => 3, 'words_per_section' => ['min' => 120, 'max' => 220], 'guidance' => 'about 500-700 words'],
        'default' => ['sections' => 4, 'words_per_section' => ['min' => 160, 'max' => 280], 'guidance' => 'about 800-1100 words'],
        'long' => ['sections' => 5, 'words_per_section' => ['min' => 180, 'max' => 320], 'guidance' => 'about 1200-1600 words'],
    ],

    'sources' => [
        'target_min' => 3,
        'target_max' => 5,
        'preferred_host_suffixes' => [
            'snitchsocial.net',
            'tiktok.com',
            'instagram.com',
            'youtube.com',
            'linkedin.com',
            'facebook.com',
            'meta.com',
            'hootsuite.com',
            'sproutsocial.com',
            'later.com',
            'buffer.com',
            'socialmediatoday.com',
            'marketingbrew.com',
            'techcrunch.com',
            'theverge.com',
            'bbc.co.uk',
            'theguardian.com',
        ],
        'blocked_host_suffixes' => [
            'brandwatch.com',
            'mention.com',
            'meltwater.com',
            'sprinklr.com',
        ],
    ],

    /*
    | SEO topic clusters for blog:generate. Pick avoiding recent titles/tags.
    */
    'seo_clusters' => [
        [
            'id' => 'competitor-tracking',
            'primary' => 'competitor social media tracking',
            'supporting' => [
                'track competitor posts',
                'social competitive intelligence',
                'monitor rival creators',
            ],
            'angle_hints' => [
                'local brands and agencies',
                'public posts only',
                'one contact sheet across platforms',
            ],
        ],
        [
            'id' => 'tiktok-hooks',
            'primary' => 'TikTok hooks that win',
            'supporting' => [
                'TikTok competitor analysis',
                'hook patterns for short video',
                'remake winning TikToks',
            ],
            'angle_hints' => [
                'first three seconds',
                'pattern interrupts',
                'what rivals post this week',
            ],
        ],
        [
            'id' => 'instagram-reels',
            'primary' => 'Instagram Reels competitor strategy',
            'supporting' => [
                'analyse competitor Reels',
                'Instagram content ideas from rivals',
                'Reels hooks and craft',
            ],
            'angle_hints' => [
                'visual craft',
                'caption vs hook',
                'agency workflows',
            ],
        ],
        [
            'id' => 'youtube-shorts',
            'primary' => 'YouTube Shorts competitive research',
            'supporting' => [
                'Shorts ideas from competitors',
                'track YouTube rivals',
                'Shorts hooks worth remaking',
            ],
            'angle_hints' => [
                'channel vs Shorts cadence',
                'title packaging',
            ],
        ],
        [
            'id' => 'winners-remakes',
            'primary' => 'how to remake winning social posts',
            'supporting' => [
                'score competitor winners',
                'remake brief for creators',
                'what to copy from rivals',
            ],
            'angle_hints' => [
                'rules-based winners',
                'hooks visuals SFX',
                'ethical remakes of public craft',
            ],
        ],
        [
            'id' => 'agency-intel',
            'primary' => 'agency competitive social listening',
            'supporting' => [
                'client competitor tracking',
                'multi-brand social intel',
                'report what rivals posted',
            ],
            'angle_hints' => [
                'retainers and reporting',
                'fewer tools more proof',
            ],
        ],
        [
            'id' => 'cross-platform',
            'primary' => 'cross-platform competitor content tracking',
            'supporting' => [
                'TikTok Instagram YouTube in one feed',
                'compare rivals across platforms',
                'unified competitor feed',
            ],
            'angle_hints' => [
                'platform mix',
                'avoid tab-hopping',
            ],
        ],
    ],

];
