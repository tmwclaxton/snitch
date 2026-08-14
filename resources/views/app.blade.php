<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}"  @class(['dark' => ($appearance ?? 'system') == 'dark'])>
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        {{-- Inline script to detect system dark mode preference and apply it immediately --}}
        <script>
            (function() {
                const appearance = '{{ $appearance ?? "system" }}';

                if (appearance === 'system') {
                    const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;

                    if (prefersDark) {
                        document.documentElement.classList.add('dark');
                    }
                }
            })();
        </script>

        {{-- Inline style matches snitch paper so the first paint matches the shell --}}
        <style>
            html {
                background-color: #efe6d8;
            }

            html.dark {
                background-color: #1c1915;
            }
        </style>

        <link rel="icon" href="/favicon.ico" sizes="any">
        <link rel="icon" href="/favicon.svg" type="image/svg+xml">
        <link rel="icon" href="/favicon.png" type="image/png" sizes="48x48">
        <link rel="apple-touch-icon" href="/apple-touch-icon.png">

        @php
            $seo = $page['props']['seo'] ?? null;
            $seoSite = is_array($seo) ? ($seo['site_name'] ?? config('app.name', 'Snitch')) : config('app.name', 'Snitch');
            $seoTitle = is_array($seo) ? ($seo['title'] ?? $seoSite) : $seoSite;
            $seoFullTitle = ($seoTitle === $seoSite) ? $seoSite : "{$seoTitle} - {$seoSite}";
            $seoDescription = is_array($seo) ? ($seo['description'] ?? '') : '';
            $seoImage = is_array($seo) ? ($seo['image'] ?? '') : '';
            $seoCanonical = is_array($seo) ? ($seo['canonical'] ?? '') : '';
            $seoRobots = is_array($seo) ? ($seo['robots'] ?? 'index, follow') : 'index, follow';
            $seoLocale = is_array($seo) ? ($seo['locale'] ?? 'en_GB') : 'en_GB';
            $seoTwitterCard = is_array($seo) ? ($seo['twitter_card'] ?? 'summary_large_image') : 'summary_large_image';
            $seoJsonLd = is_array($seo) && is_array($seo['json_ld'] ?? null) ? $seo['json_ld'] : [];
        @endphp

        @if (is_array($seo))
            <title inertia>{{ $seoFullTitle }}</title>
            <meta inertia head-key="description" name="description" content="{{ $seoDescription }}">
            <meta inertia head-key="robots" name="robots" content="{{ $seoRobots }}">
            <meta inertia head-key="og:site_name" property="og:site_name" content="{{ $seoSite }}">
            <meta inertia head-key="og:title" property="og:title" content="{{ $seoFullTitle }}">
            <meta inertia head-key="og:description" property="og:description" content="{{ $seoDescription }}">
            <meta inertia head-key="og:type" property="og:type" content="website">
            <meta inertia head-key="og:image" property="og:image" content="{{ $seoImage }}">
            <meta inertia head-key="og:url" property="og:url" content="{{ $seoCanonical }}">
            <meta inertia head-key="og:locale" property="og:locale" content="{{ $seoLocale }}">
            <meta inertia head-key="twitter:card" name="twitter:card" content="{{ $seoTwitterCard }}">
            <meta inertia head-key="twitter:title" name="twitter:title" content="{{ $seoFullTitle }}">
            <meta inertia head-key="twitter:description" name="twitter:description" content="{{ $seoDescription }}">
            <meta inertia head-key="twitter:image" name="twitter:image" content="{{ $seoImage }}">
            <link inertia head-key="canonical" rel="canonical" href="{{ $seoCanonical }}">
            @foreach ($seoJsonLd as $index => $node)
                <script type="application/ld+json" inertia head-key="ld-json-{{ $index }}">{!! json_encode($node, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!}</script>
            @endforeach
        @endif

        @fonts

        @php
            $gaId = \App\Support\GoogleAnalytics::measurementId();
            $gaEnabled = \App\Support\GoogleAnalytics::enabled();
        @endphp

        @if ($gaEnabled && is_string($gaId))
            <link rel="preconnect" href="https://www.googletagmanager.com">
            <link rel="dns-prefetch" href="https://www.google-analytics.com">
            {{-- First-byte gtag so installed PWA / standalone shells still measure before Vite boots. send_page_view is off; the first view is sent below, later Inertia visits in googleAnalytics.ts. --}}
            <script async src="https://www.googletagmanager.com/gtag/js?id={{ $gaId }}"></script>
            <script>
                window.dataLayer = window.dataLayer || [];
                function gtag(){dataLayer.push(arguments);}
                window.gtag = gtag;
                gtag('js', new Date());
                (function () {
                    var displayMode = 'browser';
                    var standaloneQuery = window.matchMedia('(display-mode: standalone)');
                    if (standaloneQuery.matches || window.navigator.standalone === true) {
                        displayMode = 'standalone';
                    } else if (window.matchMedia('(display-mode: fullscreen)').matches) {
                        displayMode = 'fullscreen';
                    } else if (window.matchMedia('(display-mode: minimal-ui)').matches) {
                        displayMode = 'minimal-ui';
                    }
                    gtag('set', 'user_properties', { pwa_display: displayMode });
                    gtag('config', @json($gaId), {
                        send_page_view: false,
                        transport_type: 'beacon',
                        cookie_domain: 'auto',
                        cookie_flags: 'SameSite=Lax;Secure'
                    });
                    gtag('event', 'page_view', {
                        page_title: document.title,
                        page_location: window.location.href,
                        page_path: window.location.pathname + window.location.search,
                        pwa_display: displayMode
                    });
                })();
            </script>
        @endif

        @vite(['resources/css/app.css', 'resources/js/app.ts', "resources/js/pages/{$page['component']}.vue"])
        <x-inertia::head>
            @unless (is_array($seo))
                <title>{{ config('app.name', 'Snitch') }}</title>
            @endunless
        </x-inertia::head>
    </head>
    <body class="font-sans antialiased">
        <x-inertia::app />
    </body>
</html>
