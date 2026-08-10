<?php

namespace App\Services\Competitors;

use App\Enums\Platform;
use App\Exceptions\InsufficientCompetitorSuggestionsException;
use App\Models\BrandProfile;
use App\Models\TrackedAccount;
use App\Services\Analysis\NanoGptClient;
use App\Services\Apify\Adapters\AbstractPlatformAdapter;
use App\Services\Apify\ApifyClient;
use App\Services\Apify\PlatformAdapterManager;
use App\Services\Firecrawl\FirecrawlClient;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class CompetitorSuggestionService
{
    public function __construct(
        public FirecrawlClient $firecrawl,
        public NanoGptClient $nanoGpt,
        public PlatformAdapterManager $adapters,
        public ApifyClient $apify,
    ) {}

    /**
     * @param  (callable(list<array{platform: string, handle: string, url: string, display_name: string, avatar: string|null, source: string|null}>): void)|null  $onProgress
     * @return list<array{platform: string, handle: string, url: string, display_name: string, avatar: string|null, source: string|null}>
     */
    public function suggest(BrandProfile $brand, ?callable $onProgress = null): array
    {
        $hits = $this->search($brand);

        if ($hits === []) {
            throw new RuntimeException('Firecrawl search returned no competitor leads.');
        }

        $candidates = $this->propose($brand, $hits);
        $verified = $this->verify($candidates, $brand, $hits, $onProgress);

        $min = max(1, (int) config('snitch.competitor_suggest.min_suggestions', 6));

        if (count($verified) < $min) {
            throw new InsufficientCompetitorSuggestionsException(
                $verified,
                'Only '.count($verified)." verified competitor profiles found (need at least {$min}).",
            );
        }

        $max = max($min, (int) config('snitch.competitor_suggest.max_suggestions', 16));

        return array_slice($verified, 0, $max);
    }

    /**
     * @return list<array{url: string, title: string, description: string}>
     */
    public function search(BrandProfile $brand): array
    {
        if ((string) config('snitch.firecrawl.api_key') === '') {
            throw new RuntimeException('FIRECRAWL_API_KEY is not configured.');
        }

        $limit = max(1, (int) config('snitch.competitor_suggest.search_limit', 8));

        return $this->firecrawl->searchMany($this->searchQueries($brand), ['limit' => $limit]);
    }

    /**
     * @param  list<array{url: string, title: string, description: string}>  $hits
     * @return list<array{name: string, platform: string, handle: string, source: string|null}>
     */
    public function propose(BrandProfile $brand, array $hits = []): array
    {
        if ((string) config('snitch.nanogpt.api_key') === '') {
            throw new RuntimeException('NANOGPT_API_KEY is not configured.');
        }

        if ($hits === []) {
            throw new RuntimeException('Competitor suggestion requires Firecrawl search hits.');
        }

        $maxCandidates = max(1, (int) config('snitch.competitor_suggest.max_candidates', 16));
        $platforms = $this->configuredPlatforms();
        $ownHandles = $this->normalizedOwnHandles($brand);
        $ownSummary = $ownHandles === []
            ? 'none'
            : implode(', ', array_map(
                fn (string $platform, string $handle): string => "{$platform}:@{$handle}",
                array_keys($ownHandles),
                array_values($ownHandles),
            ));

        $response = $this->nanoGpt->chat([
            [
                'role' => 'system',
                'content' => 'You normalise competitor organisations and social handles from web search evidence. Reply with JSON only. Ground every suggestion in the provided search hits (titles, urls, descriptions, or social links). Never invent placeholder handles like *_local, *tips, or slug-derived fakes. Never suggest the brand itself or its own handles. Prefer real public org pages. Omit a platform when unsure. Niche rivals only - not lifestyle, meme, or unrelated accounts. Multiple platforms per org are encouraged when the evidence supports them.',
            ],
            [
                'role' => 'user',
                'content' => $this->proposeUserPrompt($brand, $ownSummary, $platforms, $maxCandidates, $hits),
            ],
        ], (string) config('snitch.competitor_suggest.model'), [
            'temperature' => (float) config('snitch.competitor_suggest.temperature', 0.3),
            'max_tokens' => (int) config('snitch.competitor_suggest.max_tokens', 1600),
            'response_format' => ['type' => 'json_object'],
        ]);

        $text = $this->nanoGpt->extractAssistantText($response);
        $payload = json_decode($this->extractJson($text), true);

        if (! is_array($payload)) {
            throw new RuntimeException('Competitor suggestion model returned invalid JSON.');
        }

        $seeded = $this->candidatesFromSearchHits($hits, $platforms);
        $normalized = $this->normalizeCandidates($payload, $platforms, $maxCandidates, $hits);

        return $this->mergeCandidates($seeded, $normalized);
    }

    /**
     * @param  list<array{name: string, platform: string, handle: string, source?: string|null}>  $candidates
     * @param  list<array{url: string, title: string, description: string}>  $hits
     * @param  (callable(list<array{platform: string, handle: string, url: string, display_name: string, avatar: string|null, source: string|null}>): void)|null  $onProgress
     * @return list<array{platform: string, handle: string, url: string, display_name: string, avatar: string|null, source: string|null}>
     */
    public function verify(array $candidates, BrandProfile $brand, array $hits = [], ?callable $onProgress = null): array
    {
        $ownHandles = $this->normalizedOwnHandles($brand);
        $tracked = $this->trackedKeys($brand->user_id);
        $seen = [];
        $suggestions = [];
        $counts = [];
        $max = max(1, (int) config('snitch.competitor_suggest.max_suggestions', 16));
        $min = max(1, (int) config('snitch.competitor_suggest.min_suggestions', 6));
        $softCap = max(1, (int) config('snitch.competitor_suggest.max_per_platform', 3));
        $concurrency = max(1, (int) config('snitch.competitor_suggest.resolve_concurrency', 4));
        $platforms = $this->configuredPlatforms();
        $ordered = $this->interleaveByPlatform($candidates, $platforms);

        // Soft-cap first for fair mix; only relax the cap to meet the minimum floor.
        foreach ([true, false] as $enforceSoftCap) {
            $limit = $enforceSoftCap ? $max : max($min, count($suggestions));

            while (count($suggestions) < $limit) {
                $batch = $this->takeVerifyBatch(
                    $ordered,
                    $seen,
                    $tracked,
                    $ownHandles,
                    $brand,
                    $counts,
                    $softCap,
                    $enforceSoftCap,
                    min($concurrency, $limit - count($suggestions)),
                );

                if ($batch === []) {
                    break;
                }

                $profiles = $this->resolveVerifyBatch($batch);
                $added = false;

                foreach ($batch as $batchIndex => $item) {
                    $profile = $profiles[$batchIndex] ?? null;

                    if ($profile === null) {
                        continue;
                    }

                    $platform = $item['candidate']['platform'];
                    $handle = $item['handle'];
                    $resolvedHandle = ltrim((string) ($profile['handle'] ?? $handle), '@');
                    $resolvedKey = "{$platform}:{$resolvedHandle}";

                    if ($resolvedHandle === '' || isset($tracked[$resolvedKey])) {
                        continue;
                    }

                    if (($ownHandles[$platform] ?? null) === $resolvedHandle) {
                        continue;
                    }

                    // Drop fallback-only resolves (actor found nothing useful).
                    if (($profile['external_id'] ?? null) === null) {
                        continue;
                    }

                    // Numeric Facebook IDs only keep if Apify remapped to a vanity handle.
                    if ($this->isJunkFacebookHandle($platform, $resolvedHandle)) {
                        continue;
                    }

                    if (isset($seen[$resolvedKey]) && $resolvedKey !== "{$platform}:{$handle}") {
                        continue;
                    }

                    $seen[$resolvedKey] = true;

                    $displayName = $this->resolveSuggestionDisplayName(
                        $platform,
                        filled($profile['display_name'] ?? null) ? (string) $profile['display_name'] : null,
                        $item['candidate']['name'],
                        $resolvedHandle,
                    );

                    $suggestions[] = [
                        'platform' => $platform,
                        'handle' => $resolvedHandle,
                        'url' => filled($profile['url'] ?? null)
                            ? (string) $profile['url']
                            : $this->defaultUrl(Platform::from($platform), $resolvedHandle),
                        'display_name' => $displayName,
                        'avatar' => filled($profile['avatar'] ?? null) ? (string) $profile['avatar'] : null,
                        'source' => $item['candidate']['source'] ?? $this->sourceSnipFor($displayName, $resolvedHandle, $hits),
                    ];
                    $counts[$platform] = ($counts[$platform] ?? 0) + 1;
                    $added = true;

                    if (count($suggestions) >= $limit) {
                        break;
                    }
                }

                if ($added && $onProgress !== null) {
                    $onProgress($suggestions);
                }
            }
        }

        return $suggestions;
    }

    /**
     * @param  list<array{name: string, platform: string, handle: string, source?: string|null}|null>  $ordered
     * @param  array<string, true>  $seen
     * @param  array<string, true>  $tracked
     * @param  array<string, string>  $ownHandles
     * @return list<array{index: int, handle: string, candidate: array{name: string, platform: string, handle: string, source?: string|null}}>
     */
    private function takeVerifyBatch(
        array &$ordered,
        array &$seen,
        array $tracked,
        array $ownHandles,
        BrandProfile $brand,
        array $counts,
        int $softCap,
        bool $enforceSoftCap,
        int $size,
    ): array {
        $batch = [];
        $batchCounts = $counts;

        foreach ($ordered as $index => $candidate) {
            if (count($batch) >= $size) {
                break;
            }

            if ($candidate === null) {
                continue;
            }

            $platform = $candidate['platform'];
            $handle = $candidate['handle'];
            $key = "{$platform}:{$handle}";

            if (isset($seen[$key]) || isset($tracked[$key])) {
                $ordered[$index] = null;

                continue;
            }

            if (($ownHandles[$platform] ?? null) === $handle) {
                $ordered[$index] = null;

                continue;
            }

            if ($this->looksLikeOwnBrand($brand, $handle, $candidate['name'])) {
                $ordered[$index] = null;

                continue;
            }

            if ($this->isJunkFacebookHandle($platform, $handle)) {
                $ordered[$index] = null;

                continue;
            }

            if (
                $enforceSoftCap
                && ($batchCounts[$platform] ?? 0) >= $softCap
                && $this->otherPlatformsHaveCandidates($ordered, $platform, $index)
            ) {
                continue;
            }

            $seen[$key] = true;
            $ordered[$index] = null;
            $batchCounts[$platform] = ($batchCounts[$platform] ?? 0) + 1;
            $batch[] = [
                'index' => $index,
                'handle' => $handle,
                'candidate' => $candidate,
            ];
        }

        return $batch;
    }

    /**
     * @param  list<array{index: int, handle: string, candidate: array{name: string, platform: string, handle: string, source?: string|null}}>  $batch
     * @return array<int, array{
     *     platform: Platform,
     *     handle: string,
     *     url: string,
     *     external_id: string|null,
     *     avatar: string|null,
     *     display_name: string|null
     * }|null>
     */
    private function resolveVerifyBatch(array $batch): array
    {
        $profiles = [];
        $actorJobs = [];
        $adapters = [];

        foreach ($batch as $batchIndex => $item) {
            $platform = $item['candidate']['platform'];

            $adapter = $this->adapters->for($platform);
            $resolveTarget = $this->resolveTargetForCandidate($item['candidate']);

            if ($adapter instanceof AbstractPlatformAdapter) {
                $job = $adapter->resolveActorJob($resolveTarget);
                $actorJobs[$batchIndex] = [
                    'actorId' => $job['actorId'],
                    'input' => $job['input'],
                ];
                $adapters[$batchIndex] = [
                    'adapter' => $adapter,
                    'handle' => $job['handle'],
                ];

                continue;
            }

            try {
                $profiles[$batchIndex] = $adapter->resolveProfile($resolveTarget);
            } catch (Throwable) {
                $profiles[$batchIndex] = null;
            }
        }

        if ($actorJobs !== []) {
            $itemsByKey = $this->apify->runActors($actorJobs);

            foreach ($adapters as $batchIndex => $meta) {
                $profiles[$batchIndex] = $meta['adapter']->profileFromActorItems(
                    $itemsByKey[$batchIndex] ?? [],
                    $meta['handle'],
                );
            }
        }

        return $profiles;
    }

    /**
     * @param  array{name: string, platform: string, handle: string, source?: string|null, profile_kind?: string}  $candidate
     */
    private function resolveTargetForCandidate(array $candidate): string
    {
        if (($candidate['platform'] ?? '') !== 'linkedin') {
            return $candidate['handle'];
        }

        $kind = ($candidate['profile_kind'] ?? 'company') === 'in' ? 'in' : 'company';

        return "https://linkedin.com/{$kind}/{$candidate['handle']}";
    }

    /**
     * @return list<string>
     */
    public function searchQueries(BrandProfile $brand): array
    {
        $name = trim($brand->name);
        $host = $this->websiteHost($brand->website);
        $niche = $this->nicheSearchPhrase($brand);
        $queries = [];

        if ($name !== '') {
            $queries[] = "{$name} competitors alternatives";
            $queries[] = "{$name} vs similar tools brands";
        }

        if ($niche !== '' && strcasecmp($niche, $name) !== 0) {
            $queries[] = "{$niche} competitors alternatives software tools";
            $queries[] = "{$niche} influencers creators social media";
        }

        if ($host !== null) {
            $queries[] = "competitors alternatives related:{$host}";
        }

        // Niche-led per-platform searches. Brand-name-only site: queries return junk TikToks.
        $platformTopic = $niche !== '' ? $niche : $name;

        if ($platformTopic !== '') {
            foreach ($this->configuredPlatforms() as $platform) {
                $queries[] = match ($platform) {
                    'instagram' => "{$platformTopic} site:instagram.com",
                    'tiktok' => "{$platformTopic} site:tiktok.com",
                    'youtube' => "{$platformTopic} Shorts OR channel site:youtube.com",
                    'linkedin' => "{$platformTopic} (site:linkedin.com/company OR site:linkedin.com/in)",
                    'facebook' => "{$platformTopic} site:facebook.com",
                    default => "{$platformTopic} {$platform}",
                };
            }
        }

        return array_values(array_unique(array_filter($queries)));
    }

    private function nicheSearchPhrase(BrandProfile $brand): string
    {
        $name = trim($brand->name);
        $description = trim(preg_replace('/\s+/', ' ', (string) ($brand->description ?? '')) ?? '');

        if ($description === '') {
            return $name;
        }

        // Prefer concrete niche nouns over the full marketing blurb / brand name echo.
        if (preg_match_all('/\b(grants?|fundraising|nonprofit|non-profit|charit(?:y|ies)|founder|startup|scholarship|funding)\b/i', $description, $matches) >= 1) {
            $tokens = array_values(array_unique(array_map(strtolower(...), $matches[0])));

            return trim(implode(' ', array_slice($tokens, 0, 4)).' software');
        }

        return Str::limit($description, 60, '');
    }

    private function websiteHost(?string $website): ?string
    {
        if (! filled($website)) {
            return null;
        }

        $host = parse_url($website, PHP_URL_HOST);

        if (! is_string($host) || $host === '') {
            $host = parse_url('https://'.ltrim($website, '/'), PHP_URL_HOST);
        }

        if (! is_string($host) || $host === '') {
            return null;
        }

        return str_starts_with($host, 'www.') ? substr($host, 4) : $host;
    }

    /**
     * @param  list<array{url: string, title: string, description: string}>  $hits
     * @param  list<string>  $platforms
     * @return list<array{name: string, platform: string, handle: string, source: string|null}>
     */
    private function candidatesFromSearchHits(array $hits, array $platforms): array
    {
        $platformSet = array_fill_keys($platforms, true);
        $candidates = [];
        $seen = [];

        foreach ($hits as $hit) {
            $parsed = $this->socialFromUrl($hit['url']);

            if ($parsed === null || ! isset($platformSet[$parsed['platform']])) {
                continue;
            }

            $key = "{$parsed['platform']}:{$parsed['handle']}";

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $rawName = $hit['title'] !== '' ? $hit['title'] : $parsed['handle'];
            $cleaned = $this->cleanSuggestionName($rawName, $parsed['platform']);
            $name = ($cleaned !== '' && ! $this->looksLikeVideoOrSeoTitle($cleaned, $parsed['platform']))
                ? $cleaned
                : $parsed['handle'];
            $candidate = [
                'name' => Str::limit($name, 80, ''),
                'platform' => $parsed['platform'],
                'handle' => $parsed['handle'],
                'source' => $this->snip($hit),
            ];

            if (isset($parsed['profile_kind'])) {
                $candidate['profile_kind'] = $parsed['profile_kind'];
            }

            $candidates[] = $candidate;
        }

        return $candidates;
    }

    /**
     * @return array{platform: string, handle: string, profile_kind?: string}|null
     */
    private function socialFromUrl(string $url): ?array
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $host = str_starts_with($host, 'www.') ? substr($host, 4) : $host;
        $path = trim((string) parse_url($url, PHP_URL_PATH), '/');
        $segments = array_values(array_filter(explode('/', $path)));

        if ($segments === []) {
            return null;
        }

        $platform = match (true) {
            str_contains($host, 'instagram.com') => 'instagram',
            str_contains($host, 'tiktok.com') => 'tiktok',
            str_contains($host, 'facebook.com') || str_contains($host, 'fb.com') => 'facebook',
            str_contains($host, 'linkedin.com') => 'linkedin',
            str_contains($host, 'youtube.com') || str_contains($host, 'youtu.be') => 'youtube',
            default => null,
        };

        if ($platform === null) {
            return null;
        }

        $profileKind = null;

        if ($platform === 'linkedin') {
            if (! in_array($segments[0], ['company', 'in'], true)) {
                return null;
            }

            $profileKind = $segments[0];
            $handle = $segments[1] ?? null;
        } else {
            $handle = match ($platform) {
                'facebook' => in_array($segments[0], ['pages', 'groups', 'watch', 'share', 'people', 'profile.php'], true)
                    ? ($segments[1] ?? null)
                    : $segments[0],
                'youtube' => match (true) {
                    str_starts_with($segments[0], '@') => ltrim($segments[0], '@'),
                    $segments[0] === 'c' || $segments[0] === 'channel' || $segments[0] === 'user' => $segments[1] ?? null,
                    $segments[0] === 'shorts' || $segments[0] === 'watch' || $segments[0] === 'embed' => null,
                    default => ltrim($segments[0], '@'),
                },
                'tiktok', 'instagram' => ltrim($segments[0], '@'),
                default => null,
            };
        }

        $normalized = $this->normalizeHandle($handle, $platform);

        if ($normalized === null) {
            return null;
        }

        $parsed = [
            'platform' => $platform,
            'handle' => $normalized,
        ];

        if ($profileKind === 'company' || $profileKind === 'in') {
            $parsed['profile_kind'] = $profileKind;
        }

        return $parsed;
    }

    /**
     * @param  list<string>  $platforms
     * @param  list<array{url: string, title: string, description: string}>  $hits
     * @return list<array{name: string, platform: string, handle: string, source: string|null}>
     */
    private function normalizeCandidates(array $payload, array $platforms, int $maxCandidates, array $hits = []): array
    {
        $rows = $payload['competitors'] ?? $payload['suggestions'] ?? $payload;

        if (! is_array($rows)) {
            return [];
        }

        $platformSet = array_fill_keys($platforms, true);
        $orgHandles = [];
        $orgCount = 0;

        foreach ($rows as $row) {
            if (! is_array($row) || $orgCount >= $maxCandidates) {
                break;
            }

            $name = trim((string) ($row['name'] ?? $row['org'] ?? $row['brand'] ?? ''));
            $source = isset($row['source']) && is_string($row['source'])
                ? Str::limit(trim($row['source']), 160, '')
                : $this->sourceSnipFor($name, '', $hits);
            $handles = $row['handles'] ?? null;
            $parsed = [];

            if (is_array($handles)) {
                foreach ($handles as $platform => $handle) {
                    if (! is_string($platform) || ! isset($platformSet[$platform])) {
                        continue;
                    }

                    $normalized = $this->normalizeHandle($handle, $platform);

                    if ($normalized === null) {
                        continue;
                    }

                    $candidate = [
                        'name' => $name,
                        'platform' => $platform,
                        'handle' => $normalized,
                        'source' => $source !== '' ? $source : null,
                    ];

                    if ($platform === 'linkedin') {
                        $candidate['profile_kind'] = 'company';
                    }

                    $parsed[$platform] = $candidate;
                }
            } else {
                $platform = strtolower(trim((string) ($row['platform'] ?? '')));
                $handle = $this->normalizeHandle($row['handle'] ?? null, $platform);

                if (isset($platformSet[$platform]) && $handle !== null) {
                    $candidate = [
                        'name' => $name,
                        'platform' => $platform,
                        'handle' => $handle,
                        'source' => $source !== '' ? $source : null,
                    ];

                    if ($platform === 'linkedin') {
                        $candidate['profile_kind'] = 'company';
                    }

                    $parsed[$platform] = $candidate;
                }
            }

            if ($parsed === []) {
                continue;
            }

            $orgHandles[] = $parsed;
            $orgCount++;
        }

        $flat = [];

        foreach ($orgHandles as $parsed) {
            foreach ($parsed as $candidate) {
                $flat[] = $candidate;
            }
        }

        $maxResolves = max(
            $maxCandidates,
            (int) config('snitch.competitor_suggest.max_resolves', 32),
        );

        return array_slice($this->interleaveByPlatform($flat, $platforms), 0, $maxResolves);
    }

    /**
     * @param  list<array{name: string, platform: string, handle: string, source: string|null}>  $seeded
     * @param  list<array{name: string, platform: string, handle: string, source: string|null}>  $normalized
     * @return list<array{name: string, platform: string, handle: string, source: string|null}>
     */
    private function mergeCandidates(array $seeded, array $normalized): array
    {
        $merged = [];
        $seen = [];

        // URL-seeded evidence first within each platform bucket, then LLM rows.
        foreach ([...$seeded, ...$normalized] as $candidate) {
            $key = "{$candidate['platform']}:{$candidate['handle']}";

            if (isset($seen[$key]) || $this->isJunkFacebookHandle($candidate['platform'], $candidate['handle'])) {
                continue;
            }

            $seen[$key] = true;
            $merged[] = $candidate;
        }

        $maxResolves = max(1, (int) config('snitch.competitor_suggest.max_resolves', 32));

        return array_slice(
            $this->interleaveByPlatform($merged, $this->configuredPlatforms()),
            0,
            $maxResolves,
        );
    }

    /**
     * Round-robin candidates so Facebook URL density cannot dominate verify order.
     *
     * @param  list<array{name: string, platform: string, handle: string, source?: string|null}>  $candidates
     * @param  list<string>  $platforms
     * @return list<array{name: string, platform: string, handle: string, source?: string|null}>
     */
    private function interleaveByPlatform(array $candidates, array $platforms): array
    {
        $buckets = [];

        foreach ($candidates as $candidate) {
            $buckets[$candidate['platform']][] = $candidate;
        }

        $ordered = [];
        $platformOrder = $this->platformPriority($platforms);

        while (true) {
            $added = false;

            foreach ($platformOrder as $platform) {
                if (($buckets[$platform] ?? []) === []) {
                    continue;
                }

                $ordered[] = array_shift($buckets[$platform]);
                $added = true;
            }

            if (! $added) {
                break;
            }
        }

        return $ordered;
    }

    /**
     * @param  list<array{name: string, platform: string, handle: string, source?: string|null}|null>  $ordered
     */
    private function otherPlatformsHaveCandidates(array $ordered, string $platform, int $fromIndex): bool
    {
        foreach ($ordered as $index => $candidate) {
            if ($index <= $fromIndex || $candidate === null) {
                continue;
            }

            if ($candidate['platform'] !== $platform) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $platforms
     * @return list<string>
     */
    private function platformPriority(array $platforms): array
    {
        // Non-Facebook first so verify soft-cap fills IG/TT/YT/LI before FB flood.
        $preferred = ['instagram', 'tiktok', 'youtube', 'linkedin', 'facebook'];
        $ordered = [];

        foreach ($preferred as $platform) {
            if (in_array($platform, $platforms, true)) {
                $ordered[] = $platform;
            }
        }

        foreach ($platforms as $platform) {
            if (! in_array($platform, $ordered, true)) {
                $ordered[] = $platform;
            }
        }

        return $ordered;
    }

    private function normalizeHandle(mixed $handle, ?string $platform = null): ?string
    {
        if (! is_string($handle) && ! is_int($handle)) {
            return null;
        }

        $value = ltrim(trim((string) $handle), '@');

        if ($value === '' || str_contains($value, ' ') || str_contains($value, '{')) {
            return null;
        }

        if (preg_match('/^(null|none|n\/a|unknown)$/i', $value) === 1) {
            return null;
        }

        // Drop obvious invented placeholders from weaker models.
        if (preg_match('/(_local|tips)$/i', $value) === 1) {
            return null;
        }

        if ($platform !== null && $this->isJunkFacebookHandle($platform, $value)) {
            return null;
        }

        return Str::limit($value, 80, '');
    }

    private function isJunkFacebookHandle(string $platform, string $handle): bool
    {
        if ($platform !== 'facebook') {
            return false;
        }

        // Pure numeric Facebook IDs are not useful rival handles in the UI.
        return preg_match('/^\d{6,}$/', $handle) === 1;
    }

    /**
     * @return array<string, string>
     */
    private function normalizedOwnHandles(BrandProfile $brand): array
    {
        $handles = is_array($brand->own_handles) ? $brand->own_handles : [];
        $normalized = [];

        foreach ($this->configuredPlatforms() as $platform) {
            // Keep numeric Facebook own-handles so verify can exclude them.
            $value = $this->normalizeHandle($handles[$platform] ?? null);

            if ($value !== null) {
                $normalized[$platform] = $value;
            }
        }

        return $normalized;
    }

    /**
     * @return array<string, true>
     */
    private function trackedKeys(int $userId): array
    {
        $keys = [];

        TrackedAccount::query()
            ->where('user_id', $userId)
            ->get(['platform', 'handle'])
            ->each(function (TrackedAccount $account) use (&$keys): void {
                $platform = $account->platform instanceof Platform
                    ? $account->platform->value
                    : (string) $account->platform;
                $handle = ltrim((string) $account->handle, '@');
                $keys["{$platform}:{$handle}"] = true;
            });

        return $keys;
    }

    private function looksLikeOwnBrand(BrandProfile $brand, string $handle, string $candidateName): bool
    {
        $brandSlug = Str::slug($brand->name, '');
        $handleSlug = Str::slug($handle, '');
        $nameSlug = Str::slug($candidateName, '');

        if ($brandSlug !== '' && ($handleSlug === $brandSlug || $nameSlug === $brandSlug)) {
            return true;
        }

        return false;
    }

    /**
     * Prefer Apify profile nicknames over Firecrawl/LLM video or SEO titles.
     *
     * Order: resolved display_name → cleaned org/LLM name → handle.
     */
    private function resolveSuggestionDisplayName(
        string $platform,
        ?string $profileDisplayName,
        string $candidateName,
        string $handle,
    ): string {
        $profileName = $this->cleanSuggestionName((string) $profileDisplayName, $platform);
        $cleanedCandidate = $this->cleanSuggestionName($candidateName, $platform);

        if ($profileName !== '' && strcasecmp($profileName, $handle) !== 0) {
            return $profileName;
        }

        if ($cleanedCandidate !== '' && ! $this->looksLikeVideoOrSeoTitle($cleanedCandidate, $platform)) {
            return $cleanedCandidate;
        }

        if ($profileName !== '') {
            return $profileName;
        }

        return $handle;
    }

    private function cleanSuggestionName(string $name, string $platform): string
    {
        $name = trim($name);

        if ($name === '') {
            return '';
        }

        $labels = match ($platform) {
            'tiktok' => ['TikTok', 'Tiktok'],
            'instagram' => ['Instagram'],
            'youtube' => ['YouTube', 'Youtube'],
            'facebook' => ['Facebook'],
            'linkedin' => ['LinkedIn', 'Linkedin'],
            default => [],
        };

        foreach ($labels as $label) {
            $quoted = preg_quote($label, '/');
            $name = preg_replace('/\s*[-|]\s*'.$quoted.'\s*$/i', '', $name) ?? $name;
            $name = preg_replace('/\s+on\s+'.$quoted.'\s*$/i', '', $name) ?? $name;
        }

        return trim($name);
    }

    private function looksLikeVideoOrSeoTitle(string $name, string $platform): bool
    {
        if (! in_array($platform, ['tiktok', 'youtube'], true)) {
            return false;
        }

        $trimmed = trim($name);

        if ($trimmed === '') {
            return false;
        }

        if (mb_strlen($trimmed) > 48) {
            return true;
        }

        if (str_contains($trimmed, ':') || str_contains($trimmed, '...')) {
            return true;
        }

        if (substr_count($trimmed, ' ') >= 6) {
            return true;
        }

        return preg_match(
            '/\b(ultimate guide|how to|tips? for|writing tip|strategies|understanding|fundraising)\b/i',
            $trimmed,
        ) === 1;
    }

    /**
     * @return list<string>
     */
    private function configuredPlatforms(): array
    {
        $configured = config('snitch.competitor_suggest.platforms', [
            'instagram',
            'tiktok',
            'facebook',
            'linkedin',
        ]);

        if (! is_array($configured)) {
            return ['instagram', 'tiktok', 'facebook', 'linkedin'];
        }

        return array_values(array_filter(
            $configured,
            fn (mixed $platform): bool => is_string($platform) && Platform::tryFrom($platform) !== null,
        ));
    }

    /**
     * @param  list<string>  $platforms
     * @param  list<array{url: string, title: string, description: string}>  $hits
     */
    private function proposeUserPrompt(
        BrandProfile $brand,
        string $ownSummary,
        array $platforms,
        int $maxCandidates,
        array $hits,
    ): string {
        $platformList = implode(', ', $platforms);
        $website = filled($brand->website) ? $brand->website : 'unknown';
        $description = filled($brand->description) ? $brand->description : 'unknown';
        $evidence = $this->formatSearchEvidence($hits);

        return <<<PROMPT
Brand name: {$brand->name}
Website: {$website}
Description: {$description}
Own handles (do not suggest these): {$ownSummary}

Search evidence (ground every competitor in these hits; prefer orgs/domains/social links found here):
{$evidence}

Suggest up to {$maxCandidates} distinct niche competitor organisations whose social content is worth tracking.
Focus on direct rivals and adjacent tools in the same category as the brand.
Return a fair multi-platform mix when the evidence supports it (instagram, tiktok, youtube, linkedin, facebook).
Prefer Instagram, TikTok, YouTube Shorts channels, LinkedIn company pages, and LinkedIn creator (/in/) profiles over flooding with Facebook-only rows.
Return multiple platforms per org when the evidence supports real handles.
Omit unsure handles (use null). Do not invent TikTok or YouTube handles without evidence.
Never return numeric Facebook IDs (e.g. 100081639724957); only vanity page handles.

Return JSON shaped as:
{
  "competitors": [
    {
      "name": "Org Name",
      "source": "short snip from a search hit title or description",
      "handles": {
        "instagram": "handle_or_null",
        "tiktok": "handle_or_null",
        "youtube": "handle_or_null",
        "linkedin": "company_or_in_slug_or_null",
        "facebook": "page_handle_or_null"
      }
    }
  ]
}

Only include platforms from: {$platformList}.
Return about {$maxCandidates} organisations. Prefer multi-platform rows when real handles exist.
PROMPT;
    }

    /**
     * @param  list<array{url: string, title: string, description: string}>  $hits
     */
    private function formatSearchEvidence(array $hits): string
    {
        $lines = [];

        foreach (array_slice($hits, 0, 24) as $index => $hit) {
            $n = $index + 1;
            $title = $hit['title'] !== '' ? $hit['title'] : '(no title)';
            $description = $hit['description'] !== '' ? $hit['description'] : '';
            $lines[] = "{$n}. {$title}\n   URL: {$hit['url']}\n   {$description}";
        }

        return $lines === [] ? '(none)' : implode("\n", $lines);
    }

    /**
     * @param  list<array{url: string, title: string, description: string}>  $hits
     */
    private function sourceSnipFor(string $name, string $handle, array $hits): ?string
    {
        $needles = array_values(array_filter([
            strtolower($name),
            strtolower($handle),
            Str::slug($name, ''),
        ], fn (string $value): bool => $value !== ''));

        foreach ($hits as $hit) {
            $haystack = strtolower($hit['title'].' '.$hit['description'].' '.$hit['url']);

            foreach ($needles as $needle) {
                if ($needle !== '' && str_contains($haystack, $needle)) {
                    return $this->snip($hit);
                }
            }
        }

        return null;
    }

    /**
     * @param  array{url: string, title: string, description: string}  $hit
     */
    private function snip(array $hit): string
    {
        $text = $hit['description'] !== '' ? $hit['description'] : $hit['title'];

        return Str::limit(trim(preg_replace('/\s+/', ' ', $text) ?? $text), 120, '');
    }

    private function extractJson(string $text): string
    {
        if (str_starts_with(trim($text), '{')) {
            return trim($text);
        }

        if (preg_match('/\{.*\}/s', $text, $matches) === 1) {
            return $matches[0];
        }

        return $text;
    }

    private function defaultUrl(Platform $platform, string $handle): string
    {
        return match ($platform) {
            Platform::Instagram => "https://instagram.com/{$handle}",
            Platform::TikTok => "https://tiktok.com/@{$handle}",
            Platform::Facebook => "https://facebook.com/{$handle}",
            Platform::LinkedIn => "https://linkedin.com/company/{$handle}",
            Platform::Youtube => "https://youtube.com/@{$handle}",
        };
    }
}
