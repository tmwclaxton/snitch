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
            'instagram.com',
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
            'primary' => 'Instagram competitor tracking',
            'supporting' => [
                'track competitor Instagram posts',
                'Instagram competitive intelligence',
                'monitor rival Instagram creators',
            ],
            'angle_hints' => [
                'local brands and agencies',
                'public posts only',
                'one Instagram feed for rivals',
            ],
        ],
        [
            'id' => 'instagram-hooks',
            'primary' => 'Instagram hooks that win',
            'supporting' => [
                'Instagram competitor analysis',
                'hook patterns for Reels',
                'remake winning Instagram posts',
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
            'id' => 'instagram-cadence',
            'primary' => 'when competitors post on Instagram',
            'supporting' => [
                'Instagram posting frequency',
                'best times from rival data',
                'track competitor cadence',
            ],
            'angle_hints' => [
                'weekly refresh',
                'heatmaps and timing',
                'planning around rivals',
            ],
        ],
        [
            'id' => 'winners-remakes',
            'primary' => 'how to remake winning Instagram posts',
            'supporting' => [
                'score competitor winners',
                'remake brief for creators',
                'what to copy from Instagram rivals',
            ],
            'angle_hints' => [
                'rules-based winners',
                'hooks visuals SFX',
                'ethical remakes of public craft',
            ],
        ],
        [
            'id' => 'agency-intel',
            'primary' => 'agency Instagram competitive listening',
            'supporting' => [
                'client competitor tracking on Instagram',
                'multi-brand Instagram intel',
                'report what rivals posted',
            ],
            'angle_hints' => [
                'retainers and reporting',
                'fewer tools more proof',
            ],
        ],
        [
            'id' => 'instagram-gap',
            'primary' => 'Instagram competitive gap analysis',
            'supporting' => [
                'see the gap vs Instagram rivals',
                'compare competitor engagement',
                'what you are missing on Instagram',
            ],
            'angle_hints' => [
                'follower trends',
                'top posts and formats',
                'weekly delivered insight',
            ],
        ],
    ],

];
