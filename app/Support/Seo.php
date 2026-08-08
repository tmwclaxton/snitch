<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class Seo
{
    /**
     * Resolve SEO meta for the current request (shared to Inertia + Blade).
     *
     * @return array{
     *     title: string,
     *     description: string,
     *     image: string,
     *     canonical: string,
     *     path: string,
     *     robots: string,
     *     locale: string,
     *     site_name: string,
     *     twitter_card: string,
     *     json_ld: list<array<string, mixed>>,
     *     indexable: bool
     * }
     */
    public static function forRequest(Request $request): array
    {
        $routeName = $request->route()?->getName();
        $pages = config('seo.pages', []);
        $page = is_string($routeName) && isset($pages[$routeName])
            ? $pages[$routeName]
            : null;

        $siteName = (string) config('seo.site_name', config('app.name', 'Snitch'));
        $indexable = is_array($page);
        $isMissingRoute = $routeName === null;
        $robots = $indexable
            ? (string) config('seo.public_robots', 'index, follow')
            : (string) config('seo.private_robots', 'noindex, nofollow');

        $title = match (true) {
            $indexable => (string) ($page['title'] ?? $siteName),
            $isMissingRoute => 'Page not found',
            default => $siteName,
        };

        $description = match (true) {
            $indexable => (string) ($page['description'] ?? config('seo.default_description')),
            $isMissingRoute => 'That scrap is missing from the Snitch contact sheet.',
            default => (string) config('seo.default_description'),
        };

        $imagePath = $indexable && isset($page['image'])
            ? (string) $page['image']
            : (string) config('seo.default_image', '/images/marketing/og.jpg');

        $path = self::canonicalPath($request, $routeName, $indexable);
        $canonical = self::absoluteUrl($path);
        $image = self::absoluteUrl($imagePath);

        $jsonLdType = $indexable ? (string) ($page['json_ld'] ?? 'webpage') : null;

        return [
            'title' => $title,
            'description' => $description,
            'image' => $image,
            'canonical' => $canonical,
            'path' => $path,
            'robots' => $robots,
            'locale' => (string) config('seo.locale', 'en_GB'),
            'site_name' => $siteName,
            'twitter_card' => (string) config('seo.twitter_card', 'summary_large_image'),
            'json_ld' => $jsonLdType !== null
                ? self::jsonLd($jsonLdType, $title, $description, $canonical, $image, $siteName)
                : [],
            'indexable' => $indexable,
        ];
    }

    /**
     * Sitemap entries for public indexable routes.
     *
     * @return list<array{loc: string, lastmod: string, changefreq: string, priority: string}>
     */
    public static function sitemapEntries(): array
    {
        $lastmod = Carbon::now()->toDateString();
        $entries = [];

        foreach (config('seo.pages', []) as $routeName => $page) {
            if (! is_string($routeName) || ! is_array($page)) {
                continue;
            }

            $sitemap = is_array($page['sitemap'] ?? null) ? $page['sitemap'] : [];

            $entries[] = [
                'loc' => route($routeName, absolute: true),
                'lastmod' => $lastmod,
                'changefreq' => (string) ($sitemap['changefreq'] ?? 'monthly'),
                'priority' => (string) ($sitemap['priority'] ?? '0.5'),
            ];
        }

        return $entries;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function jsonLd(
        string $type,
        string $title,
        string $description,
        string $canonical,
        string $image,
        string $siteName,
    ): array {
        $home = rtrim((string) config('app.url'), '/').'/';

        if ($type === 'website') {
            return [
                [
                    '@context' => 'https://schema.org',
                    '@type' => 'Organization',
                    'name' => $siteName,
                    'url' => $home,
                    'logo' => self::absoluteUrl('/images/brand/mascot-mark.png'),
                    'description' => (string) config('seo.default_description'),
                ],
                [
                    '@context' => 'https://schema.org',
                    '@type' => 'WebSite',
                    'name' => $siteName,
                    'url' => $home,
                    'description' => $description,
                    'publisher' => [
                        '@type' => 'Organization',
                        'name' => $siteName,
                        'url' => $home,
                    ],
                ],
            ];
        }

        return [
            [
                '@context' => 'https://schema.org',
                '@type' => 'WebPage',
                'name' => $title,
                'description' => $description,
                'url' => $canonical,
                'image' => $image,
                'isPartOf' => [
                    '@type' => 'WebSite',
                    'name' => $siteName,
                    'url' => $home,
                ],
            ],
        ];
    }

    private static function canonicalPath(Request $request, ?string $routeName, bool $indexable): string
    {
        if ($indexable && is_string($routeName)) {
            $absolute = route($routeName, absolute: true);
            $path = parse_url($absolute, PHP_URL_PATH);

            return is_string($path) && $path !== '' ? $path : '/';
        }

        $path = '/'.ltrim($request->path(), '/');

        if ($path === '//') {
            return '/';
        }

        return $path === '/' ? '/' : rtrim($path, '/');
    }

    private static function absoluteUrl(string $pathOrUrl): string
    {
        if (str_starts_with($pathOrUrl, 'http://') || str_starts_with($pathOrUrl, 'https://')) {
            return $pathOrUrl;
        }

        $root = rtrim((string) config('app.url'), '/');
        $path = '/'.ltrim($pathOrUrl, '/');

        if ($path === '/') {
            return $root.'/';
        }

        return $root.$path;
    }
}
