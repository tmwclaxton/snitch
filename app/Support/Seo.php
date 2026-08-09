<?php

namespace App\Support;

use App\Models\Blog;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

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
        $siteName = (string) config('seo.site_name', config('app.name', 'Snitch'));

        if ($routeName === 'blog.show') {
            $blog = $request->route('blog');

            if ($blog instanceof Blog) {
                return self::forBlogPost($blog, $siteName);
            }
        }

        $pages = config('seo.pages', []);
        $page = is_string($routeName) && isset($pages[$routeName])
            ? $pages[$routeName]
            : null;

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
     * Sitemap entries for public indexable routes and published blog posts.
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

        if (Schema::hasTable('blogs')) {
            Blog::query()
                ->published()
                ->orderByDesc('published_at')
                ->get(['slug', 'updated_at'])
                ->each(function (Blog $blog) use (&$entries): void {
                    $entries[] = [
                        'loc' => route('blog.show', $blog, absolute: true),
                        'lastmod' => $blog->updated_at?->toDateString() ?? Carbon::now()->toDateString(),
                        'changefreq' => 'weekly',
                        'priority' => '0.7',
                    ];
                });
        }

        return $entries;
    }

    /**
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
    private static function forBlogPost(Blog $blog, string $siteName): array
    {
        $canonical = route('blog.show', $blog, absolute: true);
        $path = parse_url($canonical, PHP_URL_PATH);
        $path = is_string($path) && $path !== '' ? $path : '/blog/'.$blog->slug;

        $image = $blog->image_url
            ?? self::absoluteUrl((string) config('seo.default_image', '/images/marketing/og.jpg'));

        if (! str_starts_with($image, 'http://') && ! str_starts_with($image, 'https://')) {
            $image = self::absoluteUrl($image);
        }

        $title = $blog->title;
        $description = $blog->excerpt !== ''
            ? $blog->excerpt
            : (string) config('seo.default_description');

        $home = rtrim((string) config('app.url'), '/').'/';

        return [
            'title' => $title,
            'description' => $description,
            'image' => $image,
            'canonical' => $canonical,
            'path' => $path,
            'robots' => (string) config('seo.public_robots', 'index, follow'),
            'locale' => (string) config('seo.locale', 'en_GB'),
            'site_name' => $siteName,
            'twitter_card' => (string) config('seo.twitter_card', 'summary_large_image'),
            'json_ld' => [
                [
                    '@context' => 'https://schema.org',
                    '@type' => 'Article',
                    'headline' => $title,
                    'description' => $description,
                    'image' => $image,
                    'url' => $canonical,
                    'datePublished' => $blog->published_at?->toIso8601String(),
                    'dateModified' => $blog->updated_at?->toIso8601String(),
                    'author' => [
                        '@type' => 'Organization',
                        'name' => $siteName,
                        'url' => $home,
                    ],
                    'publisher' => [
                        '@type' => 'Organization',
                        'name' => $siteName,
                        'url' => $home,
                        'logo' => self::absoluteUrl('/images/brand/mascot-mark.png'),
                    ],
                    'mainEntityOfPage' => [
                        '@type' => 'WebPage',
                        '@id' => $canonical,
                    ],
                ],
            ],
            'indexable' => true,
        ];
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
