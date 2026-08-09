<?php

return [

    'site_name' => env('APP_NAME', 'Snitch'),

    'default_description' => 'Snitch tracks competitor social posts across TikTok, Instagram, YouTube, and more, explains why they work, and surfaces winners you can remake.',

    'default_image' => '/images/marketing/og.jpg',

    'locale' => 'en_GB',

    'twitter_card' => 'summary_large_image',

    'public_robots' => 'index, follow',

    'private_robots' => 'noindex, nofollow',

    /*
    |--------------------------------------------------------------------------
    | Public route SEO (named routes)
    |--------------------------------------------------------------------------
    |
    | Titles are page-specific; the Blade / SeoHead layer appends " - Snitch".
    | Descriptions should stay roughly 150-160 characters.
    |
    */

    'pages' => [

        'home' => [
            'title' => 'Competitor social tracking',
            'description' => 'Track competitor posts on TikTok, Instagram, YouTube, Facebook, and LinkedIn. Snitch analyses hooks and craft, then surfaces winners worth remaking.',
            'json_ld' => 'website',
            'sitemap' => [
                'changefreq' => 'weekly',
                'priority' => '1.0',
            ],
        ],

        'about' => [
            'title' => 'About Snitch',
            'description' => 'Snitch is a competitor social tracker for local brands, creators, and agencies who want public posts analysed and winners scored in one place.',
            'json_ld' => 'webpage',
            'sitemap' => [
                'changefreq' => 'monthly',
                'priority' => '0.7',
            ],
        ],

        'how-it-works' => [
            'title' => 'How Snitch works',
            'description' => 'Add competitor accounts, sync public posts, run full-video analysis, and score winners with your rules. See how Snitch turns rival content into remakes.',
            'json_ld' => 'webpage',
            'sitemap' => [
                'changefreq' => 'monthly',
                'priority' => '0.8',
            ],
        ],

        'pricing' => [
            'title' => 'Pricing',
            'description' => 'Snitch plans with a 7-day free trial: Free (3 competitors), Basic (£20 / 10), or Pro (£99 / 50). Save 20% with yearly billing.',
            'json_ld' => 'webpage',
            'sitemap' => [
                'changefreq' => 'weekly',
                'priority' => '0.9',
            ],
        ],

        'agents' => [
            'title' => 'Agents and MCP',
            'description' => 'Connect Cursor, Claude, Codex, or any MCP client to Snitch. Create an agent account, claim it, and use tools for competitors, influencers, and winners.',
            'json_ld' => 'webpage',
            'sitemap' => [
                'changefreq' => 'weekly',
                'priority' => '0.85',
            ],
        ],

        'analytics' => [
            'title' => 'Public analytics',
            'description' => 'Aggregate Snitch stats for posts synced, analyses completed, and winners scored. A public dashboard with no personal data.',
            'json_ld' => 'webpage',
            'sitemap' => [
                'changefreq' => 'daily',
                'priority' => '0.6',
            ],
        ],

        'contact' => [
            'title' => 'Contact',
            'description' => 'Contact the Snitch team about competitor tracking, billing, or support. Email hello@snitchsocial.net or send a message from this form.',
            'json_ld' => 'webpage',
            'sitemap' => [
                'changefreq' => 'yearly',
                'priority' => '0.5',
            ],
        ],

        'privacy' => [
            'title' => 'Privacy policy',
            'description' => 'How Snitch handles account data, public social scraping, AI analysis, Stripe billing, and cookies. Read the privacy policy for snitchsocial.net.',
            'json_ld' => 'webpage',
            'sitemap' => [
                'changefreq' => 'yearly',
                'priority' => '0.3',
            ],
        ],

        'terms' => [
            'title' => 'Terms of use',
            'description' => 'Terms of use for Snitch: plans and trials, acceptable use, public data tracking, AI analysis disclaimers, and your responsibilities when using the product.',
            'json_ld' => 'webpage',
            'sitemap' => [
                'changefreq' => 'yearly',
                'priority' => '0.3',
            ],
        ],

        'cookies' => [
            'title' => 'Cookie notice',
            'description' => 'Cookie notice for Snitch session, preference, and essential cookies used on www.snitchsocial.net.',
            'json_ld' => 'webpage',
            'sitemap' => [
                'changefreq' => 'yearly',
                'priority' => '0.3',
            ],
        ],

        'blog.index' => [
            'title' => 'Blog',
            'description' => 'Competitor social tracking notes from Snitch: hooks, remakes, and cross-platform workflows for brands and agencies.',
            'json_ld' => 'webpage',
            'sitemap' => [
                'changefreq' => 'daily',
                'priority' => '0.8',
            ],
        ],

    ],

];
