<?php

namespace App\Services\Blog;

use App\Services\Analysis\NanoGptClient;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class BlogArticleGenerationService
{
    public function __construct(private readonly NanoGptClient $nanoGpt) {}

    /**
     * @param  (callable(string, array<string, mixed>): void)|null  $onProgress
     * @param  array{id?: string, primary: string, supporting?: list<string>, angle_hints?: list<string>}|null  $seoTarget
     * @return array{title: string, excerpt: string, body: string, tags: list<string>, sources: list<mixed>}
     */
    public function generateFullArticle(
        string $topic,
        string $research,
        string $lengthKey = 'default',
        ?callable $onProgress = null,
        ?array $seoTarget = null,
    ): array {
        $length = $this->lengthConfig($lengthKey);
        $sectionCount = (int) $length['sections'];
        $wordRange = $length['words_per_section'];
        $wordGuidance = (string) $length['guidance'];
        $seoBlock = $seoTarget !== null ? $this->seoPromptBlock($seoTarget) : '';

        $onProgress?->__invoke('planning_start', [
            'section_count' => $sectionCount,
            'words_per_section_min' => $wordRange['min'],
            'words_per_section_max' => $wordRange['max'],
        ]);

        $plan = $this->planArticle($topic, $research, $wordGuidance, $sectionCount, $wordRange, $seoBlock);

        $onProgress?->__invoke('plan_complete', [
            'title' => $plan['title'],
            'section_headings' => array_column($plan['sections'], 'heading'),
        ]);

        $bodyParts = [];
        $previousNotes = [];

        foreach ($plan['sections'] as $index => $section) {
            $sectionNum = $index + 1;
            $heading = $section['heading'];

            $onProgress?->__invoke('section_start', [
                'index' => $sectionNum,
                'total' => $sectionCount,
                'heading' => $heading,
            ]);

            $content = $this->writeSection(
                $topic,
                $research,
                $sectionCount,
                $sectionNum,
                $heading,
                $section['beats'],
                $wordRange,
                $previousNotes,
                $wordGuidance,
                $seoBlock,
            );

            $contentTrimmed = $this->stripLeadingDuplicateHeading(trim($content), $heading);

            $onProgress?->__invoke('section_done', [
                'index' => $sectionNum,
                'total' => $sectionCount,
                'content_chars' => mb_strlen($contentTrimmed),
            ]);

            $bodyParts[] = '## '.$heading."\n\n".$contentTrimmed;
            $previousNotes[] = $heading.': '.mb_substr(strip_tags($contentTrimmed), 0, 280);
        }

        if ($plan['tldr'] !== []) {
            $tldrLines = [];
            foreach ($plan['tldr'] as $index => $step) {
                $tldrLines[] = ($index + 1).'. '.$step;
            }
            $bodyParts[] = "## TL;DR\n\n".implode("\n", $tldrLines);
        }

        if ($plan['faq'] !== []) {
            $faqParts = ['## FAQ'];
            foreach ($plan['faq'] as $item) {
                $faqParts[] = '### '.$item['question']."\n\n".$item['answer'];
            }
            $bodyParts[] = implode("\n\n", $faqParts);
        }

        $cta = trim($plan['cta']) !== '' ? trim($plan['cta']) : self::defaultSoftCta();
        $bodyParts[] = "## Get started\n\n".$cta;

        $body = self::normalizeBlogBodyForDisplay(implode("\n\n", $bodyParts), (string) $plan['title']);

        return [
            'title' => $plan['title'],
            'excerpt' => $plan['excerpt'],
            'body' => $body,
            'tags' => $plan['tags'],
            'sources' => $plan['sources'],
        ];
    }

    /**
     * @param  array{min: int, max: int}  $wordRange
     * @return array{
     *     title: string,
     *     excerpt: string,
     *     tags: list<string>,
     *     sources: list<mixed>,
     *     tldr: list<string>,
     *     faq: list<array{question: string, answer: string}>,
     *     cta: string,
     *     sections: list<array{heading: string, beats: string}>
     * }
     */
    protected function planArticle(
        string $topic,
        string $research,
        string $wordGuidance,
        int $sectionCount,
        array $wordRange,
        string $seoBlock,
    ): array {
        $site = rtrim((string) config('blog.public_site_url', 'https://www.snitchsocial.net'), '/');
        $targetMin = (int) config('blog.sources.target_min', 3);
        $targetMax = (int) config('blog.sources.target_max', 5);
        $maxAttempts = (int) config('blog.generate.max_attempts_per_step', 3);
        $model = (string) config('blog.generate.model');
        $lastException = null;

        $system = <<<PROMPT
You plan long-form search-intent SEO blog articles for Snitch (snitchsocial.net).
Snitch tracks competitor social posts across TikTok, Instagram, YouTube, Facebook, and LinkedIn, explains why they work (hooks, craft, SFX), and surfaces winners worth remaking.
Return JSON only with keys:
- title, excerpt
- tags (array of 3-6 lowercase strings)
- sources (array of objects with title, url, description)
- tldr (array of 3-5 short action steps)
- faq (array of 3-5 objects with question and answer)
- cta (one soft markdown paragraph with links to {$site}/pricing and/or {$site}/login)
- sections (array of exactly {$sectionCount} objects with heading and beats)
Title must earn a search click (How to / Best / vs / year). Mention Snitch at most once in the title; prefer omitting brand from the title.
Do not invent Snitch features beyond the research brief. Public posts only - no private scraping claims.
Product links only to {$site}/... - never localhost.
For sources: only include URLs from the Web research section of the brief. Prefer {$targetMin}-{$targetMax} diverse sources. Never invent URLs. If no web research is present, return an empty sources array.
PROMPT;

        $user = <<<PROMPT
Topic:
{$topic}
{$seoBlock}
Research brief:
{$research}

Target length: {$wordGuidance} across exactly {$sectionCount} sections (~{$wordRange['min']}-{$wordRange['max']} words each), plus TL;DR and FAQ.
PROMPT;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                $decoded = $this->nanoGpt->chatJson(
                    [
                        ['role' => 'system', 'content' => $system],
                        ['role' => 'user', 'content' => $user],
                    ],
                    $model,
                    [
                        'temperature' => (float) config('blog.generate.temperature', 0.4),
                        'max_tokens' => (int) config('blog.generate.max_tokens', 2200),
                    ],
                );

                if ($decoded === null) {
                    throw new \RuntimeException('Planning returned empty JSON.');
                }

                return self::normalizeArticlePlan($decoded, $topic, $sectionCount);
            } catch (\Throwable $e) {
                $lastException = $e;
                Log::warning('Blog article planning attempt failed.', [
                    'attempt' => $attempt,
                    'message' => $e->getMessage(),
                ]);

                if ($attempt < $maxAttempts) {
                    sleep($attempt * 2);
                }
            }
        }

        throw $lastException ?? new \RuntimeException('Article planning failed.');
    }

    /**
     * @param  array{min: int, max: int}  $wordRange
     * @param  list<string>  $previousNotes
     */
    protected function writeSection(
        string $topic,
        string $research,
        int $sectionCount,
        int $sectionIndex,
        string $heading,
        string $beats,
        array $wordRange,
        array $previousNotes,
        string $wordGuidance,
        string $seoBlock,
    ): string {
        $prior = $previousNotes === [] ? '(none yet)' : implode("\n", $previousNotes);
        $maxAttempts = (int) config('blog.generate.max_attempts_per_step', 3);
        $model = (string) config('blog.generate.model');
        $site = rtrim((string) config('blog.public_site_url', 'https://www.snitchsocial.net'), '/');
        $lastException = null;

        $system = <<<PROMPT
You write one section of a long-form search-intent SEO blog article for Snitch.
Write ONLY this section's Markdown body in JSON field "content".
Do NOT repeat the section heading as ## at the start. You may use ### subheadings with different wording.
Audience: local brands, creators, and agencies. Specific, practical tone. ~{$wordRange['min']}-{$wordRange['max']} words.
Lead with useful advice; mention Snitch only where natural.
Product URLs only on {$site} - never localhost.
Do not invent product features beyond the research brief.
PROMPT;

        $user = <<<PROMPT
Topic: {$topic}
{$seoBlock}
Authoritative context:
{$research}

Section {$sectionIndex} of {$sectionCount}
Heading (context only): {$heading}
Beats: {$beats}
Overall article target: {$wordGuidance}
Already covered:
{$prior}
PROMPT;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                $decoded = $this->nanoGpt->chatJson(
                    [
                        ['role' => 'system', 'content' => $system],
                        ['role' => 'user', 'content' => $user],
                    ],
                    $model,
                    [
                        'temperature' => 0.5,
                        'max_tokens' => (int) config('blog.generate.max_tokens', 2200),
                    ],
                );

                $content = is_array($decoded) ? ($decoded['content'] ?? null) : null;

                if (! is_string($content) || trim($content) === '') {
                    throw new \RuntimeException('Section writer returned empty content.');
                }

                return $content;
            } catch (\Throwable $e) {
                $lastException = $e;
                Log::warning('Blog section writing attempt failed.', [
                    'section_index' => $sectionIndex,
                    'attempt' => $attempt,
                    'message' => $e->getMessage(),
                ]);

                if ($attempt < $maxAttempts) {
                    sleep($attempt * 2);
                }
            }
        }

        throw $lastException ?? new \RuntimeException('Section writing failed.');
    }

    /**
     * @param  array<string, mixed>  $decoded
     * @return array{
     *     title: string,
     *     excerpt: string,
     *     tags: list<string>,
     *     sources: list<mixed>,
     *     tldr: list<string>,
     *     faq: list<array{question: string, answer: string}>,
     *     cta: string,
     *     sections: list<array{heading: string, beats: string}>
     * }
     */
    public static function normalizeArticlePlan(array $decoded, string $topic, int $sectionCount): array
    {
        $title = trim((string) ($decoded['title'] ?? $topic));
        $excerpt = trim((string) ($decoded['excerpt'] ?? ''));
        if ($excerpt === '') {
            $excerpt = 'Practical notes on tracking competitor social posts and remaking what wins.';
        }

        $tags = collect($decoded['tags'] ?? [])
            ->filter(fn (mixed $tag): bool => is_string($tag) && trim($tag) !== '')
            ->map(fn (string $tag): string => Str::lower(trim($tag)))
            ->values()
            ->all();

        $sources = collect($decoded['sources'] ?? [])
            ->filter(fn (mixed $source): bool => is_array($source))
            ->values()
            ->all();

        $tldr = [];
        foreach ($decoded['tldr'] ?? [] as $step) {
            $text = self::normalizePlanText($step);
            if ($text !== '') {
                $tldr[] = $text;
            }
        }
        if ($tldr === []) {
            $tldr = [
                'Pick 3-5 rival accounts worth watching.',
                'Review recent public posts for hooks and craft.',
                'Remake winners with your brand voice, not their identity.',
            ];
        }

        $faq = [];
        foreach ($decoded['faq'] ?? [] as $item) {
            if (! is_array($item)) {
                continue;
            }
            $question = self::normalizePlanText($item['question'] ?? '');
            $answer = self::normalizePlanText($item['answer'] ?? '');
            if ($question === '' || $answer === '') {
                continue;
            }
            $faq[] = ['question' => $question, 'answer' => $answer];
        }

        $cta = self::normalizePlanText($decoded['cta'] ?? '');
        if ($cta === '') {
            $cta = self::defaultSoftCta();
        }

        $sections = [];
        foreach ($decoded['sections'] ?? [] as $section) {
            if (! is_array($section)) {
                continue;
            }
            $heading = self::normalizePlanText($section['heading'] ?? '');
            $beats = self::normalizePlanText($section['beats'] ?? '');
            if ($heading === '' || $beats === '') {
                continue;
            }
            $sections[] = ['heading' => $heading, 'beats' => $beats];
        }

        while (count($sections) < $sectionCount) {
            $n = count($sections) + 1;
            $sections[] = [
                'heading' => "Section {$n}",
                'beats' => 'Expand on competitor social tracking with practical advice.',
            ];
        }

        return [
            'title' => $title,
            'excerpt' => $excerpt,
            'tags' => $tags,
            'sources' => $sources,
            'tldr' => array_slice($tldr, 0, 5),
            'faq' => array_slice($faq, 0, 5),
            'cta' => $cta,
            'sections' => array_slice($sections, 0, $sectionCount),
        ];
    }

    public static function defaultSoftCta(): string
    {
        $site = rtrim((string) config('blog.public_site_url', 'https://www.snitchsocial.net'), '/');

        return 'If you want rival posts in one contact sheet - with analysis and winners scored by your rules - [try Snitch]('.$site.'/login) or [see pricing]('.$site.'/pricing).';
    }

    public static function normalizePlanText(mixed $value): string
    {
        if (is_string($value)) {
            return trim($value);
        }

        if (is_array($value)) {
            $parts = collect($value)
                ->map(fn (mixed $part): string => self::normalizePlanText($part))
                ->filter(fn (string $part): bool => $part !== '')
                ->values()
                ->all();

            return trim(implode(' ', $parts));
        }

        if (is_scalar($value)) {
            return trim((string) $value);
        }

        return '';
    }

    public static function normalizeBlogBodyForDisplay(string $body, string $pageTitle): string
    {
        $body = self::stripDuplicateLeadTitleFromBody($body, $pageTitle);

        return self::dedupeAdjacentDuplicateHeadingsInMarkdown($body);
    }

    public static function stripDuplicateLeadTitleFromBody(string $body, string $pageTitle): string
    {
        $lines = preg_split("/\r\n|\r|\n/", $body) ?: [];
        $i = 0;
        while ($i < count($lines) && trim($lines[$i]) === '') {
            $i++;
        }
        if ($i >= count($lines)) {
            return $body;
        }
        if (preg_match('/^#{1,6}\s+(.+)$/', trim($lines[$i]), $m) === 1) {
            if (self::normalizeHeadingPlainText($m[1]) === self::normalizeHeadingPlainText($pageTitle)) {
                array_splice($lines, $i, 1);
                while ($i < count($lines) && trim($lines[$i]) === '') {
                    array_splice($lines, $i, 1);
                }
            }
        }

        return implode("\n", $lines);
    }

    public static function dedupeAdjacentDuplicateHeadingsInMarkdown(string $markdown): string
    {
        $lines = preg_split("/\r\n|\r|\n/", $markdown) ?: [];
        $out = [];
        $lastHeadingKey = null;

        foreach ($lines as $line) {
            $trim = trim($line);
            if ($trim === '') {
                $out[] = $line;

                continue;
            }
            if (preg_match('/^(#{2,3})\s+(.+)$/', $trim, $m) === 1) {
                $key = strlen($m[1]).':'.self::normalizeHeadingPlainText($m[2]);
                if ($key === $lastHeadingKey) {
                    continue;
                }
                $lastHeadingKey = $key;
                $out[] = $line;

                continue;
            }

            $lastHeadingKey = null;
            $out[] = $line;
        }

        return implode("\n", $out);
    }

    protected function stripLeadingDuplicateHeading(string $content, string $heading): string
    {
        $lines = preg_split("/\r\n|\r|\n/", trim($content)) ?: [];
        if ($lines === []) {
            return $content;
        }
        if (preg_match('/^#{1,6}\s+(.+)$/', trim($lines[0]), $m) === 1) {
            if (self::normalizeHeadingPlainText($m[1]) === self::normalizeHeadingPlainText($heading)) {
                array_shift($lines);
                while ($lines !== [] && trim($lines[0]) === '') {
                    array_shift($lines);
                }
            }
        }

        return implode("\n", $lines);
    }

    public static function normalizeHeadingPlainText(string $text): string
    {
        return Str::lower(trim(preg_replace('/\s+/', ' ', $text) ?? $text));
    }

    /**
     * @param  array{id?: string, primary: string, supporting?: list<string>, angle_hints?: list<string>}  $seoTarget
     */
    protected function seoPromptBlock(array $seoTarget): string
    {
        $primary = $seoTarget['primary'] ?? '';
        $supporting = implode(', ', $seoTarget['supporting'] ?? []);
        $angles = implode('; ', $seoTarget['angle_hints'] ?? []);

        return "\nSEO target:\n- Primary: {$primary}\n- Supporting: {$supporting}\n- Angles: {$angles}\n";
    }

    /**
     * @return array{sections: int, words_per_section: array{min: int, max: int}, guidance: string}
     */
    protected function lengthConfig(string $lengthKey): array
    {
        $lengths = config('blog.lengths', []);
        $key = $lengthKey === 'random'
            ? array_rand($lengths)
            : $lengthKey;

        if (! isset($lengths[$key]) || ! is_array($lengths[$key])) {
            $key = 'default';
        }

        $config = $lengths[$key];

        return [
            'sections' => (int) ($config['sections'] ?? 4),
            'words_per_section' => [
                'min' => (int) ($config['words_per_section']['min'] ?? 160),
                'max' => (int) ($config['words_per_section']['max'] ?? 280),
            ],
            'guidance' => (string) ($config['guidance'] ?? 'about 800-1100 words'),
        ];
    }
}
