<?php

namespace App\Services\Onboarding;

use App\Enums\Platform;
use App\Services\Analysis\NanoGptClient;
use App\Services\Firecrawl\FirecrawlClient;
use Illuminate\Support\Str;
use Throwable;

class BrandWebsiteAutofillService
{
    public function __construct(
        public FirecrawlClient $firecrawl,
        public NanoGptClient $nanoGpt,
    ) {}

    /**
     * @return array{
     *     name: ?string,
     *     website: ?string,
     *     description: ?string,
     *     own_handles: array{
     *         instagram: ?string,
     *         tiktok: ?string,
     *         facebook: ?string,
     *         linkedin: ?string,
     *         youtube: ?string
     *     }
     * }
     */
    public function extract(string $website): array
    {
        $scraped = $this->firecrawl->scrape($website);

        $name = $this->stringFromMetadata($scraped['metadata'], ['ogSiteName', 'og:site_name', 'siteName', 'title', 'ogTitle', 'og:title']);

        if ($name !== null) {
            $name = $this->cleanBrandName($name);
        }

        $sourceBundle = $this->sourceBundleForDescription(
            $scraped['summary'] ?? null,
            $scraped['markdown'] ?? null,
            $scraped['metadata'],
            $name,
        );

        $description = $this->pickHeuristicDescription($sourceBundle);

        if ($this->descriptionNeedsRewrite($description) && filled($sourceBundle['raw'])) {
            $rewritten = $this->rewriteDescriptionWithLlm($sourceBundle['raw'], $name);
            if ($rewritten !== null) {
                $description = $rewritten;
            }
        }

        if ($description !== null) {
            $description = $this->finalizeDescription($description);
        }

        $handles = $this->handlesFromLinks([
            ...$scraped['links'],
            ...$this->linksFromMarkdown($scraped['markdown'] ?? ''),
        ]);

        return [
            'name' => $name,
            'website' => $website,
            'description' => filled($description) ? $description : null,
            'own_handles' => $handles,
        ];
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array{summary: ?string, markdown: ?string, meta: ?string, raw: string}
     */
    private function sourceBundleForDescription(?string $summary, ?string $markdown, array $metadata, ?string $name): array
    {
        $summary = filled($summary) ? trim($summary) : null;
        $fromMarkdown = filled($markdown) ? $this->descriptionFromMarkdown($markdown, $name) : null;
        $meta = $this->bestMetaDescription($metadata);

        $rawParts = array_values(array_filter([
            $summary,
            $fromMarkdown,
            $meta,
            filled($markdown) ? Str::limit(trim(strip_tags($markdown)), 1800, '') : null,
        ]));

        return [
            'summary' => $summary,
            'markdown' => $fromMarkdown,
            'meta' => $meta,
            'raw' => implode("\n\n", $rawParts),
        ];
    }

    /**
     * @param  array{summary: ?string, markdown: ?string, meta: ?string, raw: string}  $bundle
     */
    private function pickHeuristicDescription(array $bundle): ?string
    {
        foreach ([$bundle['summary'], $bundle['markdown'], $bundle['meta']] as $candidate) {
            if (! is_string($candidate) || ! filled($candidate)) {
                continue;
            }

            $cleaned = $this->finalizeDescription($candidate);

            if ($cleaned !== null && ! $this->descriptionNeedsRewrite($cleaned)) {
                return $cleaned;
            }
        }

        foreach ([$bundle['summary'], $bundle['markdown'], $bundle['meta']] as $candidate) {
            if (is_string($candidate) && filled(trim($candidate))) {
                return $this->finalizeDescription($candidate);
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function bestMetaDescription(array $metadata): ?string
    {
        foreach (['ogDescription', 'og:description', 'twitter:description', 'description'] as $key) {
            $value = $this->stringFromMetadata($metadata, [$key]);

            if ($value === null) {
                continue;
            }

            if (! $this->looksLikeSeoDump($value)) {
                return $value;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @param  list<string>  $keys
     */
    private function stringFromMetadata(array $metadata, array $keys): ?string
    {
        foreach ($keys as $key) {
            $value = $metadata[$key] ?? null;

            if (is_array($value)) {
                $value = $value[0] ?? null;
            }

            if (is_string($value) && filled(trim($value))) {
                return trim($value);
            }
        }

        return null;
    }

    private function cleanBrandName(string $name): string
    {
        $cleaned = preg_replace('/\s*[|\-:].*$/u', '', $name) ?? $name;
        $cleaned = trim($cleaned);

        return Str::limit($cleaned !== '' ? $cleaned : $name, 120, '');
    }

    private function descriptionFromMarkdown(string $markdown, ?string $brandName): ?string
    {
        $lines = preg_split('/\R+/', $markdown) ?: [];
        $paragraphs = [];

        foreach ($lines as $line) {
            $line = trim($line);

            if ($line === '' || $this->shouldSkipMarkdownLine($line)) {
                continue;
            }

            $plain = trim(strip_tags(preg_replace('/\[([^\]]+)\]\([^)]+\)/', '$1', $line) ?? $line));
            $plain = preg_replace('/\s+/u', ' ', $plain) ?? $plain;
            $plain = trim($plain, " \t\n\r\0\x0B*-•");

            if (strlen($plain) < 40 || $this->looksLikeNavOrCta($plain)) {
                continue;
            }

            $paragraphs[] = $plain;

            if (count($paragraphs) >= 3) {
                break;
            }
        }

        if ($paragraphs === []) {
            return null;
        }

        if ($brandName !== null) {
            usort($paragraphs, function (string $a, string $b) use ($brandName): int {
                return (int) Str::contains($b, $brandName, ignoreCase: true)
                    <=> (int) Str::contains($a, $brandName, ignoreCase: true);
            });
        }

        return implode(' ', array_slice($paragraphs, 0, 2));
    }

    private function shouldSkipMarkdownLine(string $line): bool
    {
        if (str_starts_with($line, '#') || str_starts_with($line, '!') || str_starts_with($line, '|')) {
            return true;
        }

        if (preg_match('/^[-*+]\s+/', $line) === 1) {
            return true;
        }

        if (preg_match('/^\[[^\]]+\]\([^)]+\)$/', $line) === 1) {
            return true;
        }

        return false;
    }

    private function looksLikeNavOrCta(string $text): bool
    {
        return preg_match(
            '/^(scroll|home|menu|login|sign up|get started|install|explore|cookie consent|accept all|reject all|customize)\b/i',
            $text,
        ) === 1;
    }

    private function looksLikeSeoDump(string $text): bool
    {
        if (preg_match('/^welcome\s+to\b/i', $text) === 1) {
            return true;
        }

        // Concatenated meta tags often join with ", Your" / ", We" after a period.
        if (preg_match('/\.\s*,\s+[A-Z]/', $text) === 1) {
            return true;
        }

        if (substr_count($text, '. ') >= 1 && preg_match('/,\s+(Your|We|Our|The)\s+/', $text) === 1) {
            return true;
        }

        if (preg_match('/\b(home|about|contact|blog|pricing)\b.*\b(home|about|contact|blog|pricing)\b/i', $text) === 1) {
            return true;
        }

        return false;
    }

    private function descriptionNeedsRewrite(?string $description): bool
    {
        if ($description === null || trim($description) === '') {
            return true;
        }

        $text = trim($description);

        if (preg_match('/^welcome\s+to\b/i', $text) === 1) {
            return true;
        }

        if ($this->looksLikeSeoDump($text)) {
            return true;
        }

        if (strlen($text) < 50) {
            return true;
        }

        if (! str_contains($text, '.') && ! str_contains($text, '!') && ! str_contains($text, '?')) {
            return true;
        }

        if (preg_match('/\b(click here|learn more|sign up now|get started free)\b/i', $text) === 1) {
            return true;
        }

        return false;
    }

    private function finalizeDescription(string $description): ?string
    {
        $text = html_entity_decode($description, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
        $text = trim($text);

        if ($text === '') {
            return null;
        }

        $text = preg_replace('/^welcome\s+to\s+/i', '', $text) ?? $text;
        $text = $this->limitToSentences($text, 4);
        $text = Str::limit(trim($text), 1000, '');

        return filled($text) ? $text : null;
    }

    private function limitToSentences(string $text, int $maxSentences): string
    {
        if (preg_match_all('/[^.!?]+[.!?]+(?:\s|$)|[^.!?]+$/u', $text, $matches) !== false) {
            $sentences = array_values(array_filter(array_map('trim', $matches[0])));

            if ($sentences !== []) {
                return trim(implode(' ', array_slice($sentences, 0, $maxSentences)));
            }
        }

        return $text;
    }

    private function rewriteDescriptionWithLlm(string $source, ?string $brandName): ?string
    {
        if ((string) config('snitch.nanogpt.api_key') === '') {
            return null;
        }

        $brand = $brandName ?: 'the brand';

        try {
            $response = $this->nanoGpt->chat([
                [
                    'role' => 'system',
                    'content' => 'You write short brand "who we are" blurbs for a scrapbook profile. Reply with plain text only: 2-4 coherent sentences in first-person plural (we/our) or clear brand voice. No title, no bullets, no Welcome to, no SEO keyword dumps, no CTAs, no nav text.',
                ],
                [
                    'role' => 'user',
                    'content' => "Brand name: {$brand}\n\nWebsite source material:\n{$source}\n\nWrite the brand description now.",
                ],
            ], (string) config('snitch.brand_autofill.model'), [
                'temperature' => (float) config('snitch.brand_autofill.temperature', 0.3),
                'max_tokens' => (int) config('snitch.brand_autofill.max_tokens', 220),
            ]);

            $text = $this->nanoGpt->extractAssistantText($response);
            $text = trim($text, " \t\n\r\0\x0B\"'");

            if ($text === '' || $this->descriptionNeedsRewrite($text)) {
                return null;
            }

            return $this->finalizeDescription($text);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return list<string>
     */
    private function linksFromMarkdown(string $markdown): array
    {
        if ($markdown === '') {
            return [];
        }

        $links = [];

        if (preg_match_all('/\[[^\]]*\]\((https?:\/\/[^)\s]+)\)/i', $markdown, $markdownLinks) > 0) {
            foreach ($markdownLinks[1] as $link) {
                $links[] = $link;
            }
        }

        if (preg_match_all('/https?:\/\/[^\s\)\]\>\"\']+/i', $markdown, $bareLinks) > 0) {
            foreach ($bareLinks[0] as $link) {
                $links[] = $link;
            }
        }

        return array_values(array_unique($links));
    }

    /**
     * @param  list<string>  $links
     * @return array{instagram: ?string, tiktok: ?string, facebook: ?string, linkedin: ?string, youtube: ?string}
     */
    private function handlesFromLinks(array $links): array
    {
        $handles = [
            'instagram' => null,
            'tiktok' => null,
            'facebook' => null,
            'linkedin' => null,
            'youtube' => null,
        ];

        foreach ($links as $link) {
            $platform = $this->platformFromUrl($link);

            if ($platform === null || $handles[$platform->value] !== null) {
                continue;
            }

            $handle = $this->handleFromUrl($platform, $link);

            if ($handle !== null) {
                $handles[$platform->value] = '@'.$handle;
            }
        }

        return $handles;
    }

    private function platformFromUrl(string $url): ?Platform
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $host = str_starts_with($host, 'www.') ? substr($host, 4) : $host;

        return match (true) {
            str_contains($host, 'instagram.com') => Platform::Instagram,
            str_contains($host, 'tiktok.com') => Platform::TikTok,
            str_contains($host, 'facebook.com') || str_contains($host, 'fb.com') => Platform::Facebook,
            str_contains($host, 'linkedin.com') => Platform::LinkedIn,
            str_contains($host, 'youtube.com') || str_contains($host, 'youtu.be') => Platform::Youtube,
            default => null,
        };
    }

    private function handleFromUrl(Platform $platform, string $url): ?string
    {
        if ($platform === Platform::Facebook) {
            $facebookId = $this->facebookIdFromQuery($url);

            if ($facebookId !== null) {
                return Str::limit($facebookId, 80, '');
            }
        }

        $path = trim((string) parse_url($url, PHP_URL_PATH), '/');

        if ($path === '') {
            return null;
        }

        $segments = array_values(array_filter(explode('/', $path)));

        if ($segments === []) {
            return null;
        }

        $candidate = match ($platform) {
            Platform::LinkedIn => ($segments[0] === 'company' || $segments[0] === 'in')
                ? ($segments[1] ?? null)
                : $segments[0],
            Platform::Facebook => in_array($segments[0], ['pages', 'groups', 'watch', 'share', 'people', 'profile.php'], true)
                ? ($segments[1] ?? null)
                : $segments[0],
            Platform::Youtube => match (true) {
                str_starts_with($segments[0], '@') => ltrim($segments[0], '@'),
                $segments[0] === 'c' || $segments[0] === 'channel' || $segments[0] === 'user' => $segments[1] ?? null,
                $segments[0] === 'shorts' || $segments[0] === 'watch' || $segments[0] === 'embed' => null,
                default => ltrim($segments[0], '@'),
            },
            Platform::TikTok, Platform::Instagram => ltrim($segments[0], '@'),
        };

        if (! is_string($candidate) || $candidate === '') {
            return null;
        }

        $candidate = ltrim($candidate, '@');

        if (preg_match('/^(reel|reels|p|tv|status|posts|photos|videos|profile\.php)$/i', $candidate) === 1) {
            return null;
        }

        return Str::limit($candidate, 80, '');
    }

    private function facebookIdFromQuery(string $url): ?string
    {
        $query = parse_url($url, PHP_URL_QUERY);

        if (! is_string($query) || $query === '') {
            return null;
        }

        parse_str($query, $params);
        $id = $params['id'] ?? null;

        if (! is_string($id) && ! is_int($id)) {
            return null;
        }

        $id = (string) $id;

        return preg_match('/^\d+$/', $id) === 1 ? $id : null;
    }
}
