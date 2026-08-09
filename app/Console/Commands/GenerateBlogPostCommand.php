<?php

namespace App\Console\Commands;

use App\Enums\BlogStatus;
use App\Models\Blog;
use App\Services\Analysis\NanoGptClient;
use App\Services\Blog\BlogArticleGenerationService;
use App\Services\Blog\NanoGptBlogHeroImageService;
use App\Services\Firecrawl\FirecrawlClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class GenerateBlogPostCommand extends Command
{
    protected $signature = 'blog:generate
                            {--length=default : Article length: short, default, long, or random}
                            {--cluster= : Force an SEO cluster id from config/blog.php}
                            {--topic= : Override topic string}
                            {--skip-image : Skip hero image generation}
                            {--status= : Status when creating: draft or published (default from config)}
                            {--dry-run : Output topic without generating or saving}';

    protected $description = 'Generate an AI blog post about competitor social tracking for Snitch';

    public function handle(
        NanoGptClient $nanoGpt,
        BlogArticleGenerationService $blogArticles,
        NanoGptBlogHeroImageService $heroImages,
        FirecrawlClient $firecrawl,
    ): int {
        $this->info('Generating Snitch blog post...');

        $lengthKey = strtolower(trim((string) $this->option('length')));
        if (! in_array($lengthKey, ['short', 'default', 'long', 'random'], true)) {
            $this->error('Invalid --length. Use short, default, long, or random.');

            return self::FAILURE;
        }

        $seoTarget = $this->resolveSeoTarget();
        $this->line('  SEO cluster: '.($seoTarget['id'] ?? 'custom'));
        $this->line('  Primary keyword: '.$seoTarget['primary']);

        $topic = trim((string) $this->option('topic'));
        if ($topic === '') {
            $topic = $this->generateTopic($nanoGpt, $seoTarget);
        }

        $this->line("  Topic: {$topic}");
        $this->line("  Length: {$lengthKey}");

        if ($this->option('dry-run')) {
            $this->info('Dry run: no article or database write.');

            return self::SUCCESS;
        }

        Log::info('blog:generate started', [
            'length_key' => $lengthKey,
            'cluster' => $seoTarget['id'] ?? null,
            'topic' => $topic,
        ]);

        $researchSources = $this->fetchResearchSources($firecrawl, $topic, $seoTarget);
        $research = $this->buildResearchBrief($topic, $seoTarget, $researchSources);

        $imagePath = null;
        if (! $this->option('skip-image')) {
            $this->line('  Generating hero image...');
            $imagePrompt = $heroImages->buildPrompt($nanoGpt, $topic, [
                'tags' => $seoTarget['supporting'] ?? [],
            ]);
            $imagePath = $heroImages->generateAndStore($imagePrompt);
            if ($imagePath) {
                $this->line('  Hero image: '.$imagePath);
            } else {
                $this->warn('  No hero image generated.');
            }
        }

        $this->line('  Writing article...');
        $post = $blogArticles->generateFullArticle(
            $topic,
            $research,
            $lengthKey,
            function (string $stage, array $context = []): void {
                if ($stage === 'planning_start') {
                    $this->line(sprintf(
                        '  Planning (%d sections)...',
                        $context['section_count'] ?? 0,
                    ));
                }
                if ($stage === 'plan_complete') {
                    $this->line('  Planned title: '.Str::limit((string) ($context['title'] ?? ''), 100));
                }
                if ($stage === 'section_start' && isset($context['index'], $context['total'])) {
                    $this->line("  Section {$context['index']} of {$context['total']}: ".Str::limit((string) ($context['heading'] ?? ''), 70));
                }
            },
            $seoTarget,
        );

        $title = $this->normaliseDashes($post['title']);
        $body = $this->rewriteLocalhostUrls($this->normaliseDashes($post['body']));
        $excerpt = $this->rewriteLocalhostUrls($this->normaliseDashes($post['excerpt']));
        $tags = array_values(array_unique(array_merge(
            array_map(fn (string $tag): string => Str::lower($tag), $seoTarget['supporting'] ?? []),
            array_map(fn (string $tag): string => $this->normaliseDashes($tag), $post['tags'] ?? []),
            isset($seoTarget['id']) ? [(string) $seoTarget['id']] : [],
        )));
        $sources = $this->mergeSources($researchSources, $post['sources'] ?? []);

        $status = $this->resolveStatus();
        $slug = $this->uniqueSlug(Str::slug($title));

        $blog = Blog::query()->create([
            'title' => $title,
            'slug' => $slug,
            'excerpt' => $excerpt,
            'body' => $body,
            'image_url' => $imagePath,
            'tags' => $tags,
            'sources' => $sources,
            'status' => $status,
            'published_at' => $status === BlogStatus::Published ? now() : null,
        ]);

        $this->newLine();
        $this->info(($status === BlogStatus::Draft ? 'Drafted: ' : 'Published: ').$blog->title);
        $this->line('  URL path: /blog/'.$blog->slug);

        return self::SUCCESS;
    }

    /**
     * @return array{id?: string, primary: string, supporting?: list<string>, angle_hints?: list<string>}
     */
    protected function resolveSeoTarget(): array
    {
        $clusters = config('blog.seo_clusters', []);
        $force = trim((string) $this->option('cluster'));

        if ($force !== '') {
            foreach ($clusters as $cluster) {
                if (is_array($cluster) && ($cluster['id'] ?? '') === $force) {
                    return $cluster;
                }
            }
            $this->warn("Unknown cluster [{$force}]; picking randomly.");
        }

        $recentTags = Blog::query()
            ->orderByDesc('id')
            ->limit(12)
            ->pluck('tags')
            ->flatten()
            ->filter(fn (mixed $tag): bool => is_string($tag))
            ->map(fn (string $tag): string => Str::lower($tag))
            ->all();

        $scored = collect($clusters)
            ->filter(fn (mixed $cluster): bool => is_array($cluster) && isset($cluster['primary']))
            ->map(function (array $cluster) use ($recentTags): array {
                $id = Str::lower((string) ($cluster['id'] ?? ''));
                $overlap = in_array($id, $recentTags, true) ? 1 : 0;

                return ['cluster' => $cluster, 'penalty' => $overlap];
            })
            ->sortBy('penalty')
            ->values();

        if ($scored->isEmpty()) {
            return [
                'primary' => 'competitor social media tracking',
                'supporting' => ['track competitor posts', 'social competitive intelligence'],
            ];
        }

        $bestPenalty = $scored->first()['penalty'];
        $pool = $scored->where('penalty', $bestPenalty)->pluck('cluster')->all();

        return $pool[array_rand($pool)];
    }

    /**
     * @param  array{id?: string, primary: string, supporting?: list<string>, angle_hints?: list<string>}  $seoTarget
     */
    protected function generateTopic(NanoGptClient $nanoGpt, array $seoTarget): string
    {
        $recentTitles = Blog::query()
            ->orderByDesc('id')
            ->limit(15)
            ->pluck('title')
            ->implode("\n- ");

        $primary = $seoTarget['primary'];
        $supporting = implode(', ', $seoTarget['supporting'] ?? []);
        $angles = implode('; ', $seoTarget['angle_hints'] ?? []);

        try {
            $decoded = $nanoGpt->chatJson(
                [
                    [
                        'role' => 'system',
                        'content' => 'Return JSON {"topic":"..."} with one specific SEO article topic for Snitch competitor social tracking. No brand stuffing. Avoid repeating recent titles.',
                    ],
                    [
                        'role' => 'user',
                        'content' => "Primary: {$primary}\nSupporting: {$supporting}\nAngles: {$angles}\nRecent titles:\n- {$recentTitles}",
                    ],
                ],
                (string) config('blog.generate.model'),
                ['temperature' => 0.7, 'max_tokens' => 200],
            );

            $topic = is_array($decoded) ? trim((string) ($decoded['topic'] ?? '')) : '';
            if ($topic !== '') {
                return $topic;
            }
        } catch (\Throwable $e) {
            Log::warning('blog:generate topic generation failed', ['message' => $e->getMessage()]);
        }

        return 'How to track competitor posts on TikTok and Instagram without drowning in tabs';
    }

    /**
     * @param  array{id?: string, primary: string, supporting?: list<string>}  $seoTarget
     * @return list<array{url: string, title: string, description: string}>
     */
    protected function fetchResearchSources(FirecrawlClient $firecrawl, string $topic, array $seoTarget): array
    {
        if ((string) config('snitch.firecrawl.api_key') === '') {
            $this->warn('  FIRECRAWL_API_KEY missing; continuing without web sources.');

            return [];
        }

        try {
            $limit = (int) config('blog.generate.firecrawl_search_limit', 8);
            $hits = $firecrawl->searchMany([
                $topic,
                $seoTarget['primary'].' social media',
            ], ['limit' => $limit]);

            return array_values(array_filter(
                $hits,
                fn (array $hit): bool => $this->isAllowedSourceHost($hit['url'] ?? ''),
            ));
        } catch (\Throwable $e) {
            Log::warning('blog:generate Firecrawl search failed; continuing without web sources.', [
                'message' => $e->getMessage(),
            ]);
            $this->warn('  Firecrawl search failed; continuing without web sources.');

            return [];
        }
    }

    /**
     * @param  array{id?: string, primary: string, supporting?: list<string>, angle_hints?: list<string>}  $seoTarget
     * @param  list<array{url: string, title: string, description: string}>  $researchSources
     */
    protected function buildResearchBrief(string $topic, array $seoTarget, array $researchSources): string
    {
        $site = rtrim((string) config('blog.public_site_url', 'https://www.snitchsocial.net'), '/');
        $lines = [
            'Product: Snitch ('.$site.') tracks public competitor social posts, analyses hooks/craft/SFX, and scores winners for remakes.',
            'Topic: '.$topic,
            'Primary keyword: '.$seoTarget['primary'],
            'Supporting: '.implode(', ', $seoTarget['supporting'] ?? []),
            'Angles: '.implode('; ', $seoTarget['angle_hints'] ?? []),
            '',
            'Web research (Firecrawl):',
        ];

        if ($researchSources === []) {
            $lines[] = '(none)';
        } else {
            foreach (array_slice($researchSources, 0, 8) as $hit) {
                $lines[] = '- '.$hit['title'].' | '.$hit['url'].' | '.$hit['description'];
            }
        }

        return implode("\n", $lines);
    }

    protected function resolveStatus(): BlogStatus
    {
        $option = strtolower(trim((string) $this->option('status')));
        if ($option === '') {
            $option = strtolower((string) config('blog.default_generate_status', 'draft'));
        }

        return $option === 'published' ? BlogStatus::Published : BlogStatus::Draft;
    }

    protected function uniqueSlug(string $base, ?int $ignoreId = null): string
    {
        $slug = $base !== '' ? $base : 'snitch-post';
        $candidate = $slug;
        $i = 2;

        while (
            Blog::query()
                ->when($ignoreId !== null, fn ($q) => $q->where('id', '!=', $ignoreId))
                ->where('slug', $candidate)
                ->exists()
        ) {
            $candidate = $slug.'-'.$i;
            $i++;
        }

        return $candidate;
    }

    protected function isAllowedSourceHost(string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST);
        if (! is_string($host) || $host === '') {
            return false;
        }

        $host = Str::lower($host);
        foreach (config('blog.sources.blocked_host_suffixes', []) as $blocked) {
            if (is_string($blocked) && ($host === $blocked || str_ends_with($host, '.'.$blocked))) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  list<array{url: string, title: string, description: string}>  $research
     * @param  list<mixed>  $planned
     * @return list<array{title: string, url: string, description: string}>
     */
    protected function mergeSources(array $research, array $planned): array
    {
        $byUrl = [];

        foreach ($research as $hit) {
            $url = $hit['url'] ?? '';
            if (! is_string($url) || $url === '' || ! $this->isAllowedSourceHost($url)) {
                continue;
            }
            $byUrl[$url] = [
                'title' => $hit['title'] !== '' ? $hit['title'] : $url,
                'url' => $url,
                'description' => Str::limit($hit['description'] ?? '', 160, ''),
            ];
        }

        foreach ($planned as $source) {
            if (! is_array($source)) {
                continue;
            }
            $url = is_string($source['url'] ?? null) ? $source['url'] : '';
            if ($url === '' || ! isset($byUrl[$url])) {
                continue;
            }
            if (is_string($source['title'] ?? null) && $source['title'] !== '') {
                $byUrl[$url]['title'] = $source['title'];
            }
            if (is_string($source['description'] ?? null) && $source['description'] !== '') {
                $byUrl[$url]['description'] = Str::limit($source['description'], 160, '');
            }
        }

        $max = (int) config('blog.sources.target_max', 5);

        return array_slice(array_values($byUrl), 0, $max);
    }

    protected function normaliseDashes(string $text): string
    {
        return str_replace(["\u{2014}", "\u{2013}", '—', '–'], '-', $text);
    }

    protected function rewriteLocalhostUrls(string $text): string
    {
        $site = rtrim((string) config('blog.public_site_url', 'https://www.snitchsocial.net'), '/');

        return (string) preg_replace(
            '#https?://(localhost|127\.0\.0\.1)(:\d+)?#i',
            $site,
            $text,
        );
    }
}
