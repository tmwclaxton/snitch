<?php

namespace App\Services\Influencers;

use App\Enums\Platform;
use App\Exceptions\InsufficientInfluencerSuggestionsException;
use App\Models\BrandProfile;
use App\Models\TrackedAccount;
use App\Services\Analysis\NanoGptClient;
use App\Services\Apify\Adapters\AbstractPlatformAdapter;
use App\Services\Apify\Adapters\InstagramAdapter;
use App\Services\Apify\Adapters\TikTokAdapter;
use App\Services\Apify\Adapters\YoutubeAdapter;
use App\Services\Apify\ApifyClient;
use App\Services\Apify\PlatformAdapterManager;
use App\Services\Firecrawl\FirecrawlClient;
use App\Services\Scraping\ApifyMonthlyCapGate;
use App\Services\TikHub\Adapters\InstagramAdapter as TikHubInstagramAdapter;
use App\Services\TikHub\Adapters\TikTokAdapter as TikHubTikTokAdapter;
use App\Services\TikHub\Adapters\YoutubeAdapter as TikHubYoutubeAdapter;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class InfluencerDiscoveryService
{
    public function __construct(
        public FirecrawlClient $firecrawl,
        public NanoGptClient $nanoGpt,
        public PlatformAdapterManager $adapters,
        public ApifyClient $apify,
        public ApifyMonthlyCapGate $apifyCapGate,
    ) {}

    /**
     * @param  array{
     *     platforms: list<string>,
     *     language: string|null,
     *     min_followers: int|null,
     *     max_followers: int|null,
     *     brief: string
     * }  $filters
     * @param  (callable(list<array<string, mixed>>): void)|null  $onProgress
     * @return list<array<string, mixed>>
     */
    public function discover(BrandProfile $brand, array $filters, ?callable $onProgress = null): array
    {
        $platforms = $this->normalizePlatforms($filters['platforms'] ?? []);

        if ($platforms === []) {
            throw new RuntimeException('Select at least one platform.');
        }

        $seeds = config('snitch.influencer_find.seeds', []);
        $useFirecrawl = (bool) ($seeds['firecrawl'] ?? true);
        $useModel = (bool) ($seeds['model'] ?? true);
        $useApifySearch = (bool) ($seeds['apify_search'] ?? true);

        $firecrawlProposed = [];
        $hits = [];

        if ($useFirecrawl) {
            try {
                $hits = $this->search($brand, $filters, $platforms);
            } catch (Throwable $exception) {
                // Multi-seed: Firecrawl outage must not abort model / Apify seeds.
                $this->reportSoftSeedFailure($exception, 'Influencer Firecrawl search soft-failed');
                $hits = [];
            }

            if ($hits !== []) {
                try {
                    $firecrawlProposed = $this->propose($brand, $filters, $platforms, $hits);
                } catch (Throwable $exception) {
                    $this->reportSoftSeedFailure($exception, 'Influencer Firecrawl propose soft-failed');
                    $firecrawlProposed = $this->candidatesFromSearchHits($hits, $platforms);
                }
            }
        }

        $modelSeed = [];

        if ($useModel) {
            try {
                $modelSeed = $this->seedFromModel($brand, $filters, $platforms);
            } catch (Throwable $exception) {
                $this->reportSoftSeedFailure($exception, 'Influencer model seed soft-failed');
                $modelSeed = [];
            }
        }

        $apifySearch = [];

        if ($useApifySearch) {
            try {
                $apifySearch = $this->seedFromApifySearch($brand, $filters, $platforms);
            } catch (Throwable $exception) {
                $this->reportSoftSeedFailure($exception, 'Influencer vendor search soft-failed');
                $apifySearch = [];
            }
        }

        $candidates = $this->mergeCandidates($platforms, $firecrawlProposed, $modelSeed, $apifySearch);
        $candidates = $this->filterCreatorCandidates($candidates);

        if ($candidates === []) {
            throw new RuntimeException('No influencer leads from Firecrawl, model seed, or vendor search.');
        }

        $verified = $this->verify($candidates, $brand, $filters, $platforms, $hits, $onProgress);
        $verified = $this->enrichFitReasons($brand, $filters, $verified);

        if ($onProgress !== null && $verified !== []) {
            $onProgress($verified);
        }

        $min = max(1, (int) config('snitch.influencer_find.min_suggestions', 6));
        $max = max($min, (int) config('snitch.influencer_find.max_suggestions', 10));

        // Soft-complete: return thin shortlists (>=1) as success so MCP/UI can use them.
        // Hard-fail only when nothing verified.
        if ($verified === []) {
            throw new InsufficientInfluencerSuggestionsException(
                [],
                'Only 0 verified influencer profiles found (need at least '.$min.').',
            );
        }

        return array_slice($verified, 0, $max);
    }

    /**
     * @param  array{
     *     platforms: list<string>,
     *     language: string|null,
     *     min_followers: int|null,
     *     max_followers: int|null
     * }  $filters
     */
    public function generateBrief(BrandProfile $brand, array $filters): string
    {
        if ((string) config('snitch.nanogpt.api_key') === '') {
            throw new RuntimeException('NANOGPT_API_KEY is not configured.');
        }

        $platforms = $this->normalizePlatforms($filters['platforms'] ?? []);
        $language = trim((string) ($filters['language'] ?? ''));
        $min = $filters['min_followers'] ?? null;
        $max = $filters['max_followers'] ?? null;
        $description = trim((string) ($brand->description ?? ''));

        $filterLines = [
            'Platforms: '.($platforms !== [] ? implode(', ', $platforms) : 'any'),
            'Language: '.($language !== '' ? $language : 'any'),
            'Follower range: '.($min !== null || $max !== null
                ? (($min !== null ? (string) $min : 'any').' - '.($max !== null ? (string) $max : 'any'))
                : 'any'),
        ];

        $response = $this->nanoGpt->chat([
            [
                'role' => 'system',
                'content' => 'You write short UK English creator-discovery briefs for a brand looking to partner with influencers. Reply with plain text only (no markdown, no JSON). 2-4 sentences. Focus on niche, audience, content style, and platforms. Do not invent specific handles.',
            ],
            [
                'role' => 'user',
                'content' => implode("\n", [
                    "Brand name: {$brand->name}",
                    'Brand description: '.($description !== '' ? $description : '(none provided - write a generic editable template)'),
                    'Filters:',
                    ...$filterLines,
                    'Write a brief the brand can edit before searching for influencers.',
                ]),
            ],
        ], (string) config('snitch.influencer_find.model'), [
            'temperature' => (float) config('snitch.influencer_find.brief_temperature', 0.4),
            'max_tokens' => (int) config('snitch.influencer_find.brief_max_tokens', 280),
        ]);

        $text = trim($this->nanoGpt->extractAssistantText($response));

        if ($text === '') {
            throw new RuntimeException('Brief generation returned empty text.');
        }

        return Str::limit($text, 1200, '');
    }

    /**
     * @param  array{
     *     platforms: list<string>,
     *     language: string|null,
     *     min_followers: int|null,
     *     max_followers: int|null,
     *     brief: string
     * }  $filters
     * @param  list<string>  $platforms
     * @return list<array{url: string, title: string, description: string}>
     */
    public function search(BrandProfile $brand, array $filters, array $platforms): array
    {
        if ((string) config('snitch.firecrawl.api_key') === '') {
            throw new RuntimeException('FIRECRAWL_API_KEY is not configured.');
        }

        $limit = max(1, (int) config('snitch.influencer_find.search_limit', 8));

        return $this->firecrawl->searchMany($this->searchQueries($brand, $filters, $platforms), ['limit' => $limit]);
    }

    /**
     * @param  array{
     *     platforms: list<string>,
     *     language: string|null,
     *     min_followers: int|null,
     *     max_followers: int|null,
     *     brief: string
     * }  $filters
     * @param  list<string>  $platforms
     * @return list<string>
     */
    public function searchQueries(BrandProfile $brand, array $filters, array $platforms): array
    {
        $brief = trim((string) ($filters['brief'] ?? ''));
        $language = trim((string) ($filters['language'] ?? ''));
        $langHint = $language !== '' && strcasecmp($language, 'any') !== 0
            ? " {$language}"
            : '';
        $min = $filters['min_followers'] ?? null;
        $max = $filters['max_followers'] ?? null;
        $bandHint = '';

        if ($min !== null || $max !== null) {
            $bandHint = ' micro influencer mid-tier';
        }

        $queries = [];
        $topic = $this->topicFromBriefAndBrand($brief, $brand);

        if ($topic !== '') {
            $queries[] = "{$topic} influencer creator{$bandHint}{$langHint}";
            $queries[] = "{$topic} content creator UGC{$langHint}";
            $queries[] = "best {$topic} influencers for brand collabs{$langHint}";
            $queries[] = "{$topic} creators list Instagram TikTok YouTube{$langHint}";
        }

        $name = trim($brand->name);

        if ($name !== '' && $topic !== '' && strcasecmp($name, $topic) !== 0) {
            $queries[] = "{$topic} creators for brands like {$name}{$langHint}";
        }

        if ($topic !== '') {
            foreach ($platforms as $platform) {
                $queries = [
                    ...$queries,
                    ...$this->platformSearchQueries($topic, $platform, $langHint, $bandHint),
                ];
            }
        }

        return array_values(array_unique(array_filter($queries)));
    }

    /**
     * @return list<string>
     */
    private function platformSearchQueries(string $topic, string $platform, string $langHint, string $bandHint): array
    {
        return match ($platform) {
            'instagram' => [
                "{$topic} influencer OR creator site:instagram.com{$langHint}",
                "{$topic} Instagram creator{$bandHint}{$langHint}",
                "{$topic} UGC creator instagram.com/{$langHint}",
            ],
            'tiktok' => [
                "{$topic} influencer OR creator site:tiktok.com/@{$langHint}",
                "{$topic} TikTok creator{$bandHint}{$langHint}",
                "{$topic} TikTok UGC creator{$langHint}",
            ],
            'youtube' => [
                "{$topic} YouTuber OR Shorts creator site:youtube.com/@{$langHint}",
                "{$topic} YouTube channel creator{$bandHint}{$langHint}",
                "{$topic} fitness OR howto Shorts creator site:youtube.com{$langHint}",
            ],
            'linkedin' => [
                "{$topic} creator influencer site:linkedin.com/in{$langHint}",
                "{$topic} thought leader creator site:linkedin.com/in{$langHint}",
                "{$topic} founder creator LinkedIn{$langHint}",
            ],
            'facebook' => [
                "{$topic} creator influencer site:facebook.com{$langHint}",
                "{$topic} Facebook creator page{$langHint}",
            ],
            default => ["{$topic} influencer {$platform}{$langHint}"],
        };
    }

    /**
     * @param  array{
     *     platforms: list<string>,
     *     language: string|null,
     *     min_followers: int|null,
     *     max_followers: int|null,
     *     brief: string
     * }  $filters
     * @param  list<string>  $platforms
     * @param  list<array{url: string, title: string, description: string}>  $hits
     * @return list<array{name: string, platform: string, handle: string, source: string|null, profile_kind?: string}>
     */
    public function propose(BrandProfile $brand, array $filters, array $platforms, array $hits): array
    {
        if ((string) config('snitch.nanogpt.api_key') === '') {
            throw new RuntimeException('NANOGPT_API_KEY is not configured.');
        }

        $maxCandidates = max(1, (int) config('snitch.influencer_find.max_candidates', 14));
        $ownHandles = $this->normalizedOwnHandles($brand, $platforms);
        $ownSummary = $ownHandles === []
            ? 'none'
            : implode(', ', array_map(
                fn (string $platform, string $handle): string => "{$platform}:@{$handle}",
                array_keys($ownHandles),
                array_values($ownHandles),
            ));

        $language = trim((string) ($filters['language'] ?? ''));
        $min = $filters['min_followers'] ?? null;
        $max = $filters['max_followers'] ?? null;
        $brief = trim((string) ($filters['brief'] ?? ''));

        $hitLines = [];

        foreach (array_slice($hits, 0, 40) as $index => $hit) {
            $hitLines[] = ($index + 1).'. '.($hit['title'] ?: '(no title)')
                .' | '.$hit['url']
                .' | '.Str::limit($hit['description'] ?? '', 140, '');
        }

        $platformRule = count($platforms) === 1
            ? 'Only suggest the single allowed platform ('.$platforms[0].'). Do not mix other platforms.'
            : 'Mix platforms when the filters allow.';

        $response = $this->nanoGpt->chat([
            [
                'role' => 'system',
                'content' => 'You find individual social creators and influencers a brand could partner with. Reply with JSON only: {"influencers":[{"name":"...","platform":"instagram|tiktok|youtube|linkedin|facebook","handle":"...","source":"short evidence","fit_reason":"1-2 sentences on why they are a good brand-deal fit"}]}. Ground every suggestion in the search hits. Prefer real people / creator accounts (UGC, lifestyle, niche experts), not brands, retailers, SaaS tools, apps, agencies, media publishers, marketplaces, cosmetics labels, incubators, foundations, or company pages (LinkedIn /in/ OK; skip /company). Reject product/tool pages (names like "simplest way to...", "create your brand", marketplaces). Reject handles that look like products or apps (*app, *hq, official brand accounts). Only keep creators whose primary content niche matches the brief. Never invent placeholder handles. Never suggest the brand itself. Extract handles from profile URLs in the hits whenever possible. Fill up to the requested count with niche-fit creators. fit_reason must be plain UK English, specific to the brief, no hype.',
            ],
            [
                'role' => 'user',
                'content' => implode("\n", [
                    "Brand: {$brand->name}",
                    'Description: '.trim((string) ($brand->description ?? '')),
                    'Brief: '.($brief !== '' ? $brief : '(none)'),
                    'Own handles to exclude: '.$ownSummary,
                    'Allowed platforms: '.implode(', ', $platforms),
                    $platformRule,
                    'Preferred language: '.($language !== '' ? $language : 'any'),
                    'Follower band hint: '.($min !== null || $max !== null
                        ? (($min !== null ? (string) $min : 'any').'-'.($max !== null ? (string) $max : 'any'))
                        : 'any'),
                    "Return up to {$maxCandidates} distinct creators (one platform+handle each).",
                    'Search hits:',
                    ...$hitLines,
                ]),
            ],
        ], (string) config('snitch.influencer_find.model'), [
            'temperature' => (float) config('snitch.influencer_find.temperature', 0.3),
            'max_tokens' => (int) config('snitch.influencer_find.max_tokens', 1600),
            'response_format' => ['type' => 'json_object'],
        ]);

        $text = $this->nanoGpt->extractAssistantText($response);
        $payload = $this->decodeModelJson($text);

        if (! is_array($payload)) {
            throw new RuntimeException('Influencer suggestion model returned invalid JSON.');
        }

        $seeded = $this->candidatesFromSearchHits($hits, $platforms);
        $normalized = $this->normalizeCandidates($payload, $platforms, $maxCandidates);

        foreach ($seeded as &$row) {
            $row['seed'] = $row['seed'] ?? 'firecrawl';
        }
        unset($row);

        foreach ($normalized as &$row) {
            $row['seed'] = $row['seed'] ?? 'firecrawl';
        }
        unset($row);

        return $this->mergeCandidates($platforms, $seeded, $normalized);
    }

    /**
     * Knowledge seed from NanoGPT. Always goes through Apify verify - never Keep unverified.
     *
     * @param  array{
     *     platforms: list<string>,
     *     language: string|null,
     *     min_followers: int|null,
     *     max_followers: int|null,
     *     brief: string
     * }  $filters
     * @param  list<string>  $platforms
     * @return list<array{name: string, platform: string, handle: string, source: string|null, seed?: string, profile_kind?: string, followers?: int|null}>
     */
    public function seedFromModel(BrandProfile $brand, array $filters, array $platforms): array
    {
        if ((string) config('snitch.nanogpt.api_key') === '') {
            throw new RuntimeException('NANOGPT_API_KEY is not configured.');
        }

        $count = max(1, (int) config('snitch.influencer_find.model_seed_count', 12));
        $ownHandles = $this->normalizedOwnHandles($brand, $platforms);
        $ownSummary = $ownHandles === []
            ? 'none'
            : implode(', ', array_map(
                fn (string $platform, string $handle): string => "{$platform}:@{$handle}",
                array_keys($ownHandles),
                array_values($ownHandles),
            ));

        $language = trim((string) ($filters['language'] ?? ''));
        $min = $filters['min_followers'] ?? null;
        $max = $filters['max_followers'] ?? null;
        $brief = trim((string) ($filters['brief'] ?? ''));
        $platformRule = count($platforms) === 1
            ? 'Only suggest the single allowed platform ('.$platforms[0].').'
            : 'Mix platforms when the filters allow.';

        $response = $this->nanoGpt->chat([
            [
                'role' => 'system',
                'content' => 'You list real public social creators a brand could partner with from your knowledge. Reply with JSON only: {"influencers":[{"name":"...","platform":"instagram|tiktok|youtube|linkedin|facebook","handle":"...","source":"model-seed","fit_reason":"1-2 sentences on why they are a good brand-deal fit"}]}. Prefer real people / creator accounts whose niche matches the brief. Do not invent placeholder handles (no user1, example, testcreator). Prefer handles you are confident are real public accounts. Never suggest brands, retailers, SaaS tools, apps, agencies, media publishers, marketplaces, cosmetics labels, incubators, foundations, or company pages (LinkedIn /in/ OK). Never suggest the brand itself. Fill up to the requested count. fit_reason must be plain UK English, specific to the brief, no hype.',
            ],
            [
                'role' => 'user',
                'content' => implode("\n", [
                    "Brand: {$brand->name}",
                    'Description: '.trim((string) ($brand->description ?? '')),
                    'Brief: '.($brief !== '' ? $brief : '(none)'),
                    'Own handles to exclude: '.$ownSummary,
                    'Allowed platforms: '.implode(', ', $platforms),
                    $platformRule,
                    'Preferred language: '.($language !== '' ? $language : 'any'),
                    'Follower band hint: '.($min !== null || $max !== null
                        ? (($min !== null ? (string) $min : 'any').'-'.($max !== null ? (string) $max : 'any'))
                        : 'any'),
                    "Return up to {$count} distinct creators (one platform+handle each).",
                ]),
            ],
        ], (string) config('snitch.influencer_find.model'), [
            'temperature' => (float) config('snitch.influencer_find.temperature', 0.3),
            'max_tokens' => (int) config('snitch.influencer_find.max_tokens', 1600),
            'response_format' => ['type' => 'json_object'],
        ]);

        $text = $this->nanoGpt->extractAssistantText($response);
        $payload = $this->decodeModelJson($text);

        if (! is_array($payload)) {
            throw new RuntimeException('Model seed returned invalid JSON.');
        }

        $normalized = $this->normalizeCandidates($payload, $platforms, $count);

        foreach ($normalized as &$row) {
            $row['source'] = 'model-seed';
            $row['seed'] = 'model-seed';
        }
        unset($row);

        return $normalized;
    }

    /**
     * Native Apify platform search seed (Instagram / TikTok / YouTube).
     *
     * @param  array{
     *     platforms: list<string>,
     *     language: string|null,
     *     min_followers: int|null,
     *     max_followers: int|null,
     *     brief: string
     * }  $filters
     * @param  list<string>  $platforms
     * @return list<array{name: string, platform: string, handle: string, source: string|null, seed?: string, profile_kind?: string, followers?: int|null}>
     */
    public function seedFromApifySearch(BrandProfile $brand, array $filters, array $platforms): array
    {
        $useTikHub = $this->apifyCapGate->isApifyExhausted() && $this->apifyCapGate->tikHubConfigured();

        if (! $useTikHub && (string) config('snitch.apify.token') === '') {
            throw new RuntimeException('APIFY_TOKEN is not configured.');
        }

        $limit = max(1, (int) config('snitch.influencer_find.apify_search_limit', 15));
        $candidates = [];
        $seen = [];

        foreach ($platforms as $platform) {
            if (! in_array($platform, ['instagram', 'tiktok', 'youtube'], true)) {
                continue;
            }

            if ($useTikHub && ! $this->apifyCapGate->tikHubSupports($platform)) {
                continue;
            }

            $queries = $this->apifySearchQueries($brand, $filters, $platform);

            if ($queries === []) {
                continue;
            }

            $query = $queries[0];

            try {
                $minFollowers = isset($filters['min_followers']) ? (int) $filters['min_followers'] : null;
                $maxFollowers = isset($filters['max_followers']) ? (int) $filters['max_followers'] : null;
                $rows = $useTikHub
                    ? $this->searchUsersViaTikHub($platform, $query, $limit)
                    : $this->searchUsersViaApify($platform, $query, $limit, $minFollowers, $maxFollowers);
            } catch (Throwable $exception) {
                $this->reportSoftSeedFailure(
                    $exception,
                    'Influencer platform search soft-failed',
                    ['platform' => $platform, 'query' => $query],
                );

                continue;
            }

            foreach ($rows as $candidate) {
                $key = "{$candidate['platform']}:{$candidate['handle']}";

                if (isset($seen[$key])) {
                    continue;
                }

                $followers = array_key_exists('followers', $candidate) ? $candidate['followers'] : null;
                $minFollowers = isset($filters['min_followers']) ? (int) $filters['min_followers'] : null;
                $maxFollowers = isset($filters['max_followers']) ? (int) $filters['max_followers'] : null;

                if (
                    $followers !== null
                    && ! $this->followersInRange((int) $followers, $minFollowers, $maxFollowers)
                ) {
                    continue;
                }

                $seen[$key] = true;
                $candidates[] = $candidate;

                if (count($candidates) >= $limit) {
                    break 2;
                }
            }
        }

        return array_slice($candidates, 0, $limit);
    }

    /**
     * @return list<array{name: string, platform: string, handle: string, source: string, seed: string, followers?: int|null}>
     */
    private function searchUsersViaTikHub(string $platform, string $query, int $limit): array
    {
        $adapter = $this->adapters->tikHubAdapter($platform);

        $rows = match (true) {
            $adapter instanceof TikHubInstagramAdapter => $adapter->searchUsers($query, $limit),
            $adapter instanceof TikHubTikTokAdapter => $adapter->searchUsers($query, $limit),
            $adapter instanceof TikHubYoutubeAdapter => $adapter->searchChannels($query, $limit),
            default => [],
        };

        return array_map(function (array $row): array {
            $row['source'] = 'tikhub-search';
            $row['seed'] = 'tikhub-search';

            return $row;
        }, $rows);
    }

    /**
     * @return list<array{name: string, platform: string, handle: string, source: string|null, seed: string, followers?: int|null}>
     */
    private function searchUsersViaApify(
        string $platform,
        string $query,
        int $limit,
        ?int $minFollowers = null,
        ?int $maxFollowers = null,
    ): array {
        $adapter = $this->adapters->apifyAdapter($platform);
        $job = match ($platform) {
            'instagram' => $adapter instanceof InstagramAdapter
                ? $adapter->searchUsersActorJob($query, $limit)
                : null,
            'tiktok' => $adapter instanceof TikTokAdapter
                ? $adapter->searchUsersActorJob($query, $limit)
                : null,
            'youtube' => $adapter instanceof YoutubeAdapter
                ? $adapter->searchChannelsActorJob($query, $limit)
                : null,
            default => null,
        };

        if ($job === null) {
            return [];
        }

        $items = $this->apify->runActor($job['actorId'], $job['input']);

        return $this->candidatesFromApifySearchItems(
            $platform,
            $items,
            $limit,
            $minFollowers,
            $maxFollowers,
        );
    }

    /**
     * @param  array{
     *     platforms: list<string>,
     *     language: string|null,
     *     min_followers: int|null,
     *     max_followers: int|null,
     *     brief: string
     * }  $filters
     * @return list<string>
     */
    public function apifySearchQueries(BrandProfile $brand, array $filters, string $platform): array
    {
        $brief = trim((string) ($filters['brief'] ?? ''));
        $language = trim((string) ($filters['language'] ?? ''));
        $langHint = $language !== '' && strcasecmp($language, 'any') !== 0
            ? " {$language}"
            : '';
        $topic = $this->topicFromBriefAndBrand($brief, $brand);

        if ($topic === '') {
            return [];
        }

        return match ($platform) {
            'instagram' => [
                trim("{$topic} influencer creator{$langHint}"),
                trim("{$topic} UGC creator{$langHint}"),
            ],
            'tiktok' => [
                trim("{$topic} creator{$langHint}"),
                trim("{$topic} influencer{$langHint}"),
            ],
            'youtube' => [
                trim("{$topic} Shorts creator{$langHint}"),
                trim("{$topic} YouTuber{$langHint}"),
            ],
            default => [trim("{$topic} creator{$langHint}")],
        };
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return list<array{name: string, platform: string, handle: string, source: string|null, seed: string, followers?: int|null}>
     */
    /**
     * @param  list<array<string, mixed>>  $items
     * @return list<array{name: string, platform: string, handle: string, source: string|null, seed: string, followers?: int|null}>
     */
    public function candidatesFromApifySearchItems(
        string $platform,
        array $items,
        int $limit,
        ?int $minFollowers = null,
        ?int $maxFollowers = null,
    ): array {
        $candidates = [];
        $seen = [];

        foreach ($items as $item) {
            if (! is_array($item) || count($candidates) >= $limit) {
                break;
            }

            $handle = null;
            $name = null;

            if ($platform === 'instagram') {
                $owner = is_array($item['owner'] ?? null) ? $item['owner'] : $item;
                $handle = $this->normalizeHandle(
                    $item['username'] ?? $item['ownerUsername'] ?? $owner['username'] ?? null,
                    $platform,
                );
                $name = (string) ($item['fullName'] ?? $owner['fullName'] ?? $item['ownerFullName'] ?? $handle ?? '');
            } elseif ($platform === 'tiktok') {
                $author = is_array($item['authorMeta'] ?? null)
                    ? $item['authorMeta']
                    : (is_array($item['author'] ?? null) ? $item['author'] : $item);
                $handle = $this->normalizeHandle(
                    $author['name'] ?? $author['uniqueId'] ?? $item['authorName'] ?? $item['uniqueId'] ?? null,
                    $platform,
                );
                $name = (string) ($author['nickName'] ?? $author['nickname'] ?? $handle ?? '');
            } elseif ($platform === 'youtube') {
                $about = is_array($item['aboutChannelInfo'] ?? null) ? $item['aboutChannelInfo'] : [];
                $handle = $this->normalizeHandle(
                    $item['channelUsername'] ?? $about['channelUsername'] ?? null,
                    $platform,
                );
                $name = (string) ($item['channelName'] ?? $about['channelName'] ?? $handle ?? '');
            }

            if ($handle === null) {
                continue;
            }

            $key = "{$platform}:{$handle}";

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $followers = $this->extractFollowers([$item]);

            // Drop known out-of-band seeds before resolve so they cannot slip through as null later.
            if (
                $followers !== null
                && ! $this->followersInRange($followers, $minFollowers, $maxFollowers)
            ) {
                continue;
            }

            $candidate = [
                'name' => $name !== '' ? $name : $handle,
                'platform' => $platform,
                'handle' => $handle,
                'source' => 'apify-search',
                'seed' => 'apify-search',
            ];

            if ($followers !== null) {
                $candidate['followers'] = $followers;
            }

            $candidates[] = $candidate;
        }

        return $candidates;
    }

    /**
     * @param  list<array{name: string, platform: string, handle: string, source?: string|null, profile_kind?: string}>  $candidates
     * @param  array{
     *     platforms: list<string>,
     *     language: string|null,
     *     min_followers: int|null,
     *     max_followers: int|null,
     *     brief: string
     * }  $filters
     * @param  list<string>  $platforms
     * @param  list<array{url: string, title: string, description: string}>  $hits
     * @param  (callable(list<array<string, mixed>>): void)|null  $onProgress
     * @return list<array<string, mixed>>
     */
    public function verify(
        array $candidates,
        BrandProfile $brand,
        array $filters,
        array $platforms,
        array $hits = [],
        ?callable $onProgress = null,
    ): array {
        $ownHandles = $this->normalizedOwnHandles($brand, $platforms);
        $tracked = $this->trackedKeys($brand->user_id);
        $seen = [];
        $suggestions = [];
        $deferred = [];
        $counts = [];
        $max = max(1, (int) config('snitch.influencer_find.max_suggestions', 10));
        $min = max(1, (int) config('snitch.influencer_find.min_suggestions', 6));
        $softCap = max(1, (int) config('snitch.influencer_find.max_per_platform', 4));
        $concurrency = max(1, (int) config('snitch.influencer_find.resolve_concurrency', 4));
        $minFollowers = isset($filters['min_followers']) ? (int) $filters['min_followers'] : null;
        $maxFollowers = isset($filters['max_followers']) ? (int) $filters['max_followers'] : null;
        $ordered = $this->prioritizeCandidatesForVerify(
            $this->interleaveByPlatform($candidates, $platforms),
            $minFollowers,
            $maxFollowers,
        );

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
                    $minFollowers,
                    $maxFollowers,
                );

                if ($batch === []) {
                    break;
                }

                $profiles = $this->resolveVerifyBatch($batch);
                $added = false;
                $pending = [];

                foreach ($batch as $batchIndex => $item) {
                    $resolved = $profiles[$batchIndex] ?? null;

                    if ($resolved === null) {
                        continue;
                    }

                    $platform = $item['candidate']['platform'];
                    $handle = $item['handle'];
                    $profile = $resolved['profile'];
                    $followers = $resolved['followers'] ?? (
                        isset($item['candidate']['followers']) ? (int) $item['candidate']['followers'] : null
                    );
                    $resolvedHandle = ltrim((string) ($profile['handle'] ?? $handle), '@');
                    $resolvedKey = "{$platform}:{$resolvedHandle}";

                    if ($resolvedHandle === '' || isset($tracked[$resolvedKey])) {
                        continue;
                    }

                    if (($ownHandles[$platform] ?? null) === $resolvedHandle) {
                        continue;
                    }

                    if (($profile['external_id'] ?? null) === null) {
                        continue;
                    }

                    if ($this->isJunkFacebookHandle($platform, $resolvedHandle)) {
                        continue;
                    }

                    if (isset($seen[$resolvedKey]) && $resolvedKey !== "{$platform}:{$handle}") {
                        continue;
                    }

                    $followerOk = $this->followersInRange($followers, $minFollowers, $maxFollowers);

                    if (! $followerOk) {
                        continue;
                    }

                    $rawDisplay = filled($profile['display_name'] ?? null)
                        ? trim((string) $profile['display_name'])
                        : '';
                    $avatar = filled($profile['avatar'] ?? null) ? (string) $profile['avatar'] : null;
                    $bandRequested = $minFollowers !== null || $maxFollowers !== null;

                    // Soft quality gate: reject empty shells when a follower band was requested.
                    if ($bandRequested && $rawDisplay === '' && $avatar === null) {
                        continue;
                    }

                    $displayName = $rawDisplay !== ''
                        ? $rawDisplay
                        : ($item['candidate']['name'] !== '' ? $item['candidate']['name'] : $resolvedHandle);

                    $fitReason = isset($item['candidate']['fit_reason']) && is_string($item['candidate']['fit_reason'])
                        ? trim($item['candidate']['fit_reason'])
                        : '';

                    $pending[$batchIndex] = [
                        'platform' => $platform,
                        'handle' => $resolvedHandle,
                        'url' => filled($profile['url'] ?? null)
                            ? (string) $profile['url']
                            : $this->defaultUrl(Platform::from($platform), $resolvedHandle),
                        'display_name' => Str::limit($displayName, 80, ''),
                        'name' => Str::limit($displayName, 80, ''),
                        'avatar' => $avatar,
                        'source' => $item['candidate']['source'] ?? null,
                        'seed' => $item['candidate']['seed'] ?? $this->inferSeedLabel($item['candidate']),
                        'followers' => $followers,
                        'language_hint' => filled($filters['language'] ?? null) ? (string) $filters['language'] : null,
                        'fit_reason' => $fitReason !== '' ? Str::limit($fitReason, 280, '') : null,
                    ];
                }

                $reject = $this->rejectOrgOrBrandKeys(array_values($pending));

                foreach ($pending as $row) {
                    $resolvedKey = "{$row['platform']}:{$row['handle']}";

                    if (isset($reject[$resolvedKey])) {
                        continue;
                    }

                    unset($row['name']);

                    $bandRequested = $minFollowers !== null || $maxFollowers !== null;

                    // Prefer known follower counts; defer unknowns only when no band was requested.
                    if ($row['followers'] === null && count($suggestions) >= $min) {
                        if (! $bandRequested) {
                            $deferred[] = $row;
                        }

                        continue;
                    }

                    // When a band is set, never accept null follower counts into the shortlist.
                    if ($bandRequested && $row['followers'] === null) {
                        continue;
                    }

                    $seen[$resolvedKey] = true;
                    $suggestions[] = $row;
                    $counts[$row['platform']] = ($counts[$row['platform']] ?? 0) + 1;
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

        // Only re-admit deferred null-follower rows when no follower band was requested.
        if (
            ($minFollowers === null && $maxFollowers === null)
            && count($suggestions) < $min
            && $deferred !== []
        ) {
            foreach ($deferred as $row) {
                if (count($suggestions) >= $max) {
                    break;
                }

                $key = "{$row['platform']}:{$row['handle']}";

                if (isset($seen[$key])) {
                    continue;
                }

                $seen[$key] = true;
                $suggestions[] = $row;
                $counts[$row['platform']] = ($counts[$row['platform']] ?? 0) + 1;

                if ($onProgress !== null) {
                    $onProgress($suggestions);
                }
            }
        }

        usort($suggestions, function (array $a, array $b): int {
            $af = $a['followers'];
            $bf = $b['followers'];

            if ($af === null && $bf === null) {
                return 0;
            }

            if ($af === null) {
                return 1;
            }

            if ($bf === null) {
                return -1;
            }

            return $bf <=> $af;
        });

        return array_slice($suggestions, 0, $max);
    }

    /**
     * @param  list<string>  $platforms
     * @return list<string>
     */
    public function normalizePlatforms(array $platforms): array
    {
        $allowed = array_map(
            fn (Platform $platform): string => $platform->value,
            Platform::cases(),
        );
        $configured = config('snitch.influencer_find.platforms', ['instagram', 'tiktok', 'youtube']);
        $allowedSet = array_fill_keys(
            array_values(array_intersect(is_array($configured) ? $configured : $allowed, $allowed)),
            true,
        );

        $normalized = [];

        foreach ($platforms as $platform) {
            $value = strtolower(trim((string) $platform));

            if (isset($allowedSet[$value])) {
                $normalized[] = $value;
            }
        }

        return array_values(array_unique($normalized));
    }

    private function nichePhrase(BrandProfile $brand): string
    {
        $name = trim($brand->name);
        $description = trim(preg_replace('/\s+/', ' ', (string) ($brand->description ?? '')) ?? '');

        if ($description === '') {
            return $name;
        }

        return Str::limit($description, 70, '');
    }

    private function topicFromBriefAndBrand(string $brief, BrandProfile $brand): string
    {
        $haystack = strtolower(trim($brief.' '.$brand->description.' '.$brand->name));

        $niches = [];

        $patterns = [
            'sneaker' => 'sneaker streetwear fashion',
            'streetwear' => 'streetwear fashion',
            'fashion' => 'fashion style',
            'beauty' => 'beauty makeup',
            'maquillaje' => 'belleza maquillaje',
            'belleza' => 'belleza maquillaje',
            'makeup' => 'beauty makeup',
            'skincare' => 'skincare beauty',
            'cafe' => 'cafe coffee food',
            'coffee' => 'coffee cafe food',
            'brunch' => 'brunch food cafe',
            'latte' => 'coffee latte food',
            'fitness' => 'fitness workout',
            'workout' => 'home workout fitness',
            'grant' => 'startup grants fundraising B2B',
            'saas' => 'B2B SaaS founders',
            'b2b' => 'B2B founders LinkedIn',
            'fundraising' => 'startup fundraising grants',
        ];

        foreach ($patterns as $needle => $phrase) {
            if (str_contains($haystack, $needle)) {
                $niches[] = $phrase;
            }
        }

        if ($niches !== []) {
            return Str::limit(implode(' ', array_slice(array_unique($niches), 0, 2)), 70, '');
        }

        if ($brief !== '') {
            return Str::limit($brief, 70, '');
        }

        return $this->nichePhrase($brand);
    }

    /**
     * Fill missing fit_reason on verified suggestions via a batched NanoGPT call.
     * Fail soft: keep rows and use a short fallback when the model is unavailable.
     *
     * @param  array{
     *     platforms: list<string>,
     *     language: string|null,
     *     min_followers: int|null,
     *     max_followers: int|null,
     *     brief: string
     * }  $filters
     * @param  list<array<string, mixed>>  $suggestions
     * @return list<array<string, mixed>>
     */
    public function enrichFitReasons(BrandProfile $brand, array $filters, array $suggestions): array
    {
        if ($suggestions === []) {
            return [];
        }

        $missingIndexes = [];

        foreach ($suggestions as $index => $row) {
            $existing = isset($row['fit_reason']) && is_string($row['fit_reason'])
                ? trim($row['fit_reason'])
                : '';

            if ($existing === '') {
                $missingIndexes[] = $index;
            } else {
                $suggestions[$index]['fit_reason'] = Str::limit($existing, 280, '');
            }
        }

        if ($missingIndexes === []) {
            return array_values($suggestions);
        }

        $brief = trim((string) ($filters['brief'] ?? ''));
        $reasons = [];

        if ((string) config('snitch.nanogpt.api_key') !== '') {
            $lines = [];

            foreach ($missingIndexes as $i => $index) {
                $row = $suggestions[$index];
                $platform = (string) ($row['platform'] ?? '');
                $handle = ltrim((string) ($row['handle'] ?? ''), '@');
                $name = trim((string) ($row['display_name'] ?? $row['name'] ?? $handle));
                $followers = $row['followers'] ?? null;
                $followerLabel = is_numeric($followers) ? (string) (int) $followers : 'unknown';
                $lines[] = ($i + 1).'. '.$platform.' @'.$handle.' | '.$name.' | followers:'.$followerLabel;
            }

            try {
                $payload = $this->nanoGpt->chatJson([
                    [
                        'role' => 'system',
                        'content' => 'You write short brand-deal fit blurbs for influencer outreach. Reply with JSON only: {"reasons":[{"platform":"instagram|tiktok|youtube|linkedin|facebook","handle":"...","fit_reason":"1-2 sentences"}]} . Each fit_reason explains why that creator is a good brand-deal fit for the brief. Plain UK English, specific, no hype, no invented metrics. Only use handles from the list.',
                    ],
                    [
                        'role' => 'user',
                        'content' => implode("\n", [
                            "Brand: {$brand->name}",
                            'Description: '.trim((string) ($brand->description ?? '')),
                            'Brief: '.($brief !== '' ? $brief : '(none)'),
                            'Creators:',
                            ...$lines,
                        ]),
                    ],
                ], (string) config('snitch.influencer_find.model'), [
                    'temperature' => 0.3,
                    'max_tokens' => 1200,
                ]);

                $rows = is_array($payload) && is_array($payload['reasons'] ?? null)
                    ? $payload['reasons']
                    : [];

                foreach ($rows as $entry) {
                    if (! is_array($entry)) {
                        continue;
                    }

                    $platform = strtolower(trim((string) ($entry['platform'] ?? '')));
                    $handle = ltrim(trim((string) ($entry['handle'] ?? '')), '@');
                    $fitReason = isset($entry['fit_reason']) && is_string($entry['fit_reason'])
                        ? trim($entry['fit_reason'])
                        : '';

                    if ($platform === '' || $handle === '' || $fitReason === '') {
                        continue;
                    }

                    $reasons["{$platform}:{$handle}"] = Str::limit($fitReason, 280, '');
                }
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        foreach ($missingIndexes as $index) {
            $row = $suggestions[$index];
            $platform = (string) ($row['platform'] ?? '');
            $handle = ltrim((string) ($row['handle'] ?? ''), '@');
            $key = "{$platform}:{$handle}";
            $suggestions[$index]['fit_reason'] = $reasons[$key]
                ?? $this->fallbackFitReason($row, $brief);
        }

        return array_values($suggestions);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function fallbackFitReason(array $row, string $brief): string
    {
        $platform = (string) ($row['platform'] ?? 'social');
        $handle = ltrim((string) ($row['handle'] ?? ''), '@');
        $niche = $brief !== '' ? Str::limit($brief, 80, '') : 'your niche';

        return "Creator @{$handle} on {$platform} looks relevant for brand deals around {$niche}.";
    }

    /**
     * Drop brand / org / tool accounts via a batched NanoGPT JSON classifier (no regex forest).
     * Fail soft: bad JSON or API errors keep candidates so verify can still run.
     *
     * @param  list<array{name: string, platform: string, handle: string, source?: string|null, seed?: string, profile_kind?: string, followers?: int|null, display_name?: string|null}>  $candidates
     * @return list<array{name: string, platform: string, handle: string, source?: string|null, seed?: string, profile_kind?: string, followers?: int|null, display_name?: string|null}>
     */
    public function filterCreatorCandidates(array $candidates): array
    {
        if ($candidates === []) {
            return [];
        }

        $reject = $this->rejectOrgOrBrandKeys($candidates);

        if ($reject === []) {
            return array_values($candidates);
        }

        return array_values(array_filter(
            $candidates,
            fn (array $candidate): bool => ! isset($reject["{$candidate['platform']}:{$candidate['handle']}"]),
        ));
    }

    /**
     * @param  list<array{platform: string, handle: string, name?: string, display_name?: string|null}>  $rows
     * @return array<string, true>
     */
    public function rejectOrgOrBrandKeys(array $rows): array
    {
        if ($rows === [] || (string) config('snitch.nanogpt.api_key') === '') {
            return [];
        }

        $unique = [];

        foreach ($rows as $row) {
            $platform = strtolower(trim((string) ($row['platform'] ?? '')));
            $handle = ltrim(trim((string) ($row['handle'] ?? '')), '@');

            if ($platform === '' || $handle === '') {
                continue;
            }

            $key = "{$platform}:{$handle}";
            $label = trim((string) ($row['display_name'] ?? $row['name'] ?? $handle));
            $unique[$key] = [
                'platform' => $platform,
                'handle' => $handle,
                'name' => $label !== '' ? $label : $handle,
            ];
        }

        if ($unique === []) {
            return [];
        }

        $lines = [];
        $index = 1;

        foreach ($unique as $entry) {
            $lines[] = $index.'. '.$entry['platform'].' @'.$entry['handle'].' | '.$entry['name'];
            $index++;
        }

        try {
            $payload = $this->nanoGpt->chatJson([
                [
                    'role' => 'system',
                    'content' => 'You classify social accounts for influencer outreach. Reply with JSON only: {"reject":[{"platform":"instagram|tiktok|youtube|linkedin|facebook","handle":"...","reason":"short"}]} . Reject only clear non-person accounts: brands, retailers, SaaS tools/apps, agencies, media publishers, marketplaces, cosmetics labels, incubators, accelerators, VCs, foundations, nonprofits, chambers, regional org chapters, and official company pages. Keep individual people, creators, founders, and UGC accounts even if notable. If unsure, keep (omit from reject). Never invent handles not in the list.',
                ],
                [
                    'role' => 'user',
                    'content' => implode("\n", [
                        'Classify these accounts. Return only rejects.',
                        ...$lines,
                    ]),
                ],
            ], (string) config('snitch.influencer_find.model'), [
                'temperature' => 0.1,
                'max_tokens' => 700,
            ]);
        } catch (Throwable $exception) {
            report($exception);

            return [];
        }

        if (! is_array($payload)) {
            return [];
        }

        $rejectRows = $payload['reject'] ?? $payload['rejected'] ?? $payload['junk'] ?? [];

        if (! is_array($rejectRows)) {
            return [];
        }

        $reject = [];

        foreach ($rejectRows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $platform = strtolower(trim((string) ($row['platform'] ?? '')));
            $handle = ltrim(trim((string) ($row['handle'] ?? '')), '@');
            $key = "{$platform}:{$handle}";

            if ($platform === '' || $handle === '' || ! isset($unique[$key])) {
                continue;
            }

            $reject[$key] = true;
        }

        return $reject;
    }

    /**
     * @param  list<array{url: string, title: string, description: string}>  $hits
     * @param  list<string>  $platforms
     * @return list<array{name: string, platform: string, handle: string, source: string|null, profile_kind?: string}>
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

            // Prefer personal LinkedIn for influencers.
            if (($parsed['profile_kind'] ?? null) === 'company') {
                continue;
            }

            $key = "{$parsed['platform']}:{$parsed['handle']}";

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $name = $hit['title'] !== '' ? Str::limit($hit['title'], 80, '') : $parsed['handle'];
            $candidate = [
                'name' => $name,
                'platform' => $parsed['platform'],
                'handle' => $parsed['handle'],
                'source' => Str::limit(trim(($hit['title'] ?? '').' '.($hit['description'] ?? '')), 160, ''),
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
                'tiktok', 'instagram' => in_array(strtolower($segments[0]), ['p', 'reel', 'reels', 'stories', 'explore', 'tv', 'tags', 'share', 'accounts'], true)
                    ? null
                    : ltrim($segments[0], '@'),
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
     * @return list<array{name: string, platform: string, handle: string, source: string|null, profile_kind?: string}>
     */
    private function normalizeCandidates(array $payload, array $platforms, int $maxCandidates): array
    {
        $rows = $payload['influencers'] ?? $payload['creators'] ?? $payload['suggestions'] ?? $payload;

        if (! is_array($rows)) {
            return [];
        }

        $platformSet = array_fill_keys($platforms, true);
        $flat = [];

        foreach ($rows as $row) {
            if (! is_array($row) || count($flat) >= $maxCandidates * 2) {
                break;
            }

            $name = trim((string) ($row['name'] ?? $row['creator'] ?? ''));
            $platform = strtolower(trim((string) ($row['platform'] ?? '')));
            $handle = $this->normalizeHandle($row['handle'] ?? null, $platform);
            $source = isset($row['source']) && is_string($row['source'])
                ? Str::limit(trim($row['source']), 160, '')
                : null;
            $fitReason = isset($row['fit_reason']) && is_string($row['fit_reason'])
                ? Str::limit(trim($row['fit_reason']), 280, '')
                : null;

            if (! isset($platformSet[$platform]) || $handle === null) {
                continue;
            }

            $candidate = [
                'name' => $name !== '' ? $name : $handle,
                'platform' => $platform,
                'handle' => $handle,
                'source' => $source,
            ];

            if ($fitReason !== null && $fitReason !== '') {
                $candidate['fit_reason'] = $fitReason;
            }

            if ($platform === 'linkedin') {
                $candidate['profile_kind'] = 'in';
            }

            $flat[] = $candidate;
        }

        $maxResolves = max(
            $maxCandidates,
            (int) config('snitch.influencer_find.max_resolves', 28),
        );

        return array_slice($this->interleaveByPlatform($flat, $platforms), 0, $maxResolves);
    }

    /**
     * Merge seed lists: dedupe by platform:handle (prefer known followers), interleave sources, cap resolves.
     *
     * @param  list<string>  $platforms
     * @param  list<array{name: string, platform: string, handle: string, source?: string|null, seed?: string, profile_kind?: string, followers?: int|null, fit_reason?: string|null}>  ...$sources
     * @return list<array{name: string, platform: string, handle: string, source: string|null, seed?: string, profile_kind?: string, followers?: int|null, fit_reason?: string|null}>
     */
    public function mergeCandidates(array $platforms, array ...$sources): array
    {
        $byKey = [];

        foreach ($sources as $sourceList) {
            foreach ($sourceList as $candidate) {
                if (! is_array($candidate)) {
                    continue;
                }

                $platform = (string) ($candidate['platform'] ?? '');
                $handle = (string) ($candidate['handle'] ?? '');

                if ($platform === '' || $handle === '' || $this->isJunkFacebookHandle($platform, $handle)) {
                    continue;
                }

                $key = "{$platform}:{$handle}";
                $incoming = [
                    'name' => (string) ($candidate['name'] ?? $handle),
                    'platform' => $platform,
                    'handle' => $handle,
                    'source' => isset($candidate['source']) && is_string($candidate['source'])
                        ? $candidate['source']
                        : null,
                    'seed' => (string) ($candidate['seed'] ?? $this->inferSeedLabel($candidate)),
                ];

                if (array_key_exists('followers', $candidate) && $candidate['followers'] !== null) {
                    $incoming['followers'] = (int) $candidate['followers'];
                }

                if (isset($candidate['profile_kind'])) {
                    $incoming['profile_kind'] = $candidate['profile_kind'];
                }

                if (isset($candidate['fit_reason']) && is_string($candidate['fit_reason']) && trim($candidate['fit_reason']) !== '') {
                    $incoming['fit_reason'] = Str::limit(trim($candidate['fit_reason']), 280, '');
                }

                if (! isset($byKey[$key])) {
                    $byKey[$key] = $incoming;

                    continue;
                }

                $existingHas = array_key_exists('followers', $byKey[$key]);
                $incomingHas = array_key_exists('followers', $incoming);

                if ($incomingHas && ! $existingHas) {
                    if (! isset($incoming['fit_reason']) && isset($byKey[$key]['fit_reason'])) {
                        $incoming['fit_reason'] = $byKey[$key]['fit_reason'];
                    }
                    $byKey[$key] = $incoming;
                } elseif (isset($incoming['fit_reason']) && ! isset($byKey[$key]['fit_reason'])) {
                    $byKey[$key]['fit_reason'] = $incoming['fit_reason'];
                }
            }
        }

        $buckets = [
            'apify-search' => [],
            'tikhub-search' => [],
            'firecrawl' => [],
            'model-seed' => [],
        ];

        foreach ($byKey as $candidate) {
            $seed = (string) ($candidate['seed'] ?? 'firecrawl');

            if (! isset($buckets[$seed])) {
                $seed = 'firecrawl';
            }

            $buckets[$seed][] = $candidate;
        }

        // Prefer known-follower rows within each seed bucket.
        foreach ($buckets as $seed => $rows) {
            usort($rows, function (array $a, array $b): int {
                $af = array_key_exists('followers', $a) ? 0 : 1;
                $bf = array_key_exists('followers', $b) ? 0 : 1;

                return $af <=> $bf;
            });
            $buckets[$seed] = $rows;
        }

        $merged = [];
        $order = ['apify-search', 'tikhub-search', 'firecrawl', 'model-seed'];

        while (true) {
            $added = false;

            foreach ($order as $seed) {
                if ($buckets[$seed] === []) {
                    continue;
                }

                $merged[] = array_shift($buckets[$seed]);
                $added = true;
            }

            if (! $added) {
                break;
            }
        }

        $maxResolves = max(1, (int) config('snitch.influencer_find.max_resolves', 28));

        return array_slice($this->interleaveByPlatform($merged, $platforms), 0, $maxResolves);
    }

    /**
     * @param  array{source?: string|null, seed?: string}  $candidate
     */
    private function inferSeedLabel(array $candidate): string
    {
        $source = (string) ($candidate['source'] ?? '');

        return match (true) {
            $source === 'apify-search' || str_starts_with($source, 'apify') => 'apify-search',
            $source === 'tikhub-search' || str_starts_with($source, 'tikhub') => 'tikhub-search',
            $source === 'model-seed' => 'model-seed',
            default => 'firecrawl',
        };
    }

    /**
     * Known in-band follower counts first; unknown next; known out-of-band last (usually skipped).
     *
     * @param  list<array{name: string, platform: string, handle: string, source?: string|null, seed?: string, profile_kind?: string, followers?: int|null}>  $candidates
     * @return list<array{name: string, platform: string, handle: string, source?: string|null, seed?: string, profile_kind?: string, followers?: int|null}>
     */
    public function prioritizeCandidatesForVerify(array $candidates, ?int $minFollowers, ?int $maxFollowers): array
    {
        $inBand = [];
        $unknown = [];
        $outOfBand = [];

        foreach ($candidates as $candidate) {
            $followers = array_key_exists('followers', $candidate) ? $candidate['followers'] : null;

            if ($followers === null) {
                $unknown[] = $candidate;

                continue;
            }

            if ($this->followersInRange((int) $followers, $minFollowers, $maxFollowers)) {
                $inBand[] = $candidate;
            } else {
                $outOfBand[] = $candidate;
            }
        }

        return [...$inBand, ...$unknown, ...$outOfBand];
    }

    /**
     * @param  list<array{name: string, platform: string, handle: string, source?: string|null, profile_kind?: string}>  $candidates
     * @param  list<string>  $platforms
     * @return list<array{name: string, platform: string, handle: string, source?: string|null, profile_kind?: string}>
     */
    private function interleaveByPlatform(array $candidates, array $platforms): array
    {
        $buckets = [];

        foreach ($candidates as $candidate) {
            $buckets[$candidate['platform']][] = $candidate;
        }

        $ordered = [];
        $platformOrder = $platforms;

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
     * @param  list<array{name: string, platform: string, handle: string, source?: string|null, seed?: string, profile_kind?: string, followers?: int|null}|null>  $ordered
     * @param  array<string, true>  $seen
     * @param  array<string, true>  $tracked
     * @param  array<string, string>  $ownHandles
     * @return list<array{index: int, handle: string, candidate: array{name: string, platform: string, handle: string, source?: string|null, seed?: string, profile_kind?: string, followers?: int|null}}>
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
        ?int $minFollowers = null,
        ?int $maxFollowers = null,
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
                array_key_exists('followers', $candidate)
                && $candidate['followers'] !== null
                && ! $this->followersInRange((int) $candidate['followers'], $minFollowers, $maxFollowers)
            ) {
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
     * @param  list<array{index: int, handle: string, candidate: array{name: string, platform: string, handle: string, source?: string|null, profile_kind?: string}}>  $batch
     * @return array<int, array{profile: array<string, mixed>, followers: int|null}|null>
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
                $profile = $adapter->resolveProfile($resolveTarget);
                $profiles[$batchIndex] = [
                    'profile' => $profile,
                    'followers' => null,
                ];
            } catch (Throwable) {
                $profiles[$batchIndex] = null;
            }
        }

        if ($actorJobs !== []) {
            $itemsByKey = $this->apify->runActors($actorJobs);

            foreach ($adapters as $batchIndex => $meta) {
                $items = $itemsByKey[$batchIndex] ?? [];
                $profile = $meta['adapter']->profileFromActorItems($items, $meta['handle']);

                if ($profile === null) {
                    $profiles[$batchIndex] = null;

                    continue;
                }

                $profiles[$batchIndex] = [
                    'profile' => $profile,
                    'followers' => $this->extractFollowers($items),
                ];
            }
        }

        return $profiles;
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    public function extractFollowers(array $items): ?int
    {
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $candidates = [
                $item['followersCount'] ?? null,
                $item['followers'] ?? null,
                $item['fansCount'] ?? null,
                $item['subscriberCount'] ?? null,
                $item['subscribers'] ?? null,
                $item['numberOfSubscribers'] ?? null,
                $item['ownerFollowersCount'] ?? null,
                data_get($item, 'authorMeta.fans'),
                data_get($item, 'authorMeta.followers'),
                data_get($item, 'author.followers'),
                data_get($item, 'author.followerCount'),
                data_get($item, 'owner.followersCount'),
                data_get($item, 'owner.edge_followed_by.count'),
                data_get($item, 'channel.subscriberCount'),
                data_get($item, 'about.numberOfFollowers'),
                data_get($item, 'aboutChannelInfo.numberOfSubscribers'),
                data_get($item, 'aboutChannelInfo.numberOfFollowers'),
                data_get($item, 'stats.followerCount'),
            ];

            foreach ($candidates as $value) {
                if (is_numeric($value) && (int) $value >= 0) {
                    return (int) $value;
                }
            }
        }

        return null;
    }

    public function followersInRange(?int $followers, ?int $min, ?int $max): bool
    {
        $bandRequested = $min !== null || $max !== null;

        if ($followers === null) {
            // Unknown counts only pass when no band was requested.
            return ! $bandRequested;
        }

        if ($min !== null && $followers < $min) {
            return false;
        }

        if ($max !== null && $followers > $max) {
            return false;
        }

        return true;
    }

    /**
     * @param  array{name: string, platform: string, handle: string, source?: string|null, profile_kind?: string}  $candidate
     */
    private function resolveTargetForCandidate(array $candidate): string
    {
        if (($candidate['platform'] ?? '') !== 'linkedin') {
            return $candidate['handle'];
        }

        $kind = ($candidate['profile_kind'] ?? 'in') === 'company' ? 'company' : 'in';

        return "https://linkedin.com/{$kind}/{$candidate['handle']}";
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

    private function normalizeHandle(mixed $handle, ?string $platform = null): ?string
    {
        if (! is_string($handle) && ! is_int($handle)) {
            return null;
        }

        $value = ltrim(trim((string) $handle), '@');

        if ($value === '' || str_contains($value, ' ') || str_contains($value, '{')) {
            return null;
        }

        if (preg_match('/^(null|none|n\/a|unknown|p|reel|reels|stories|explore|tags|share|accounts|video|watch|shorts|channel|user|c)$/i', $value) === 1) {
            return null;
        }

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

        return preg_match('/^\d{6,}$/', $handle) === 1;
    }

    /**
     * @param  list<string>  $platforms
     * @return array<string, string>
     */
    private function normalizedOwnHandles(BrandProfile $brand, array $platforms): array
    {
        $handles = is_array($brand->own_handles) ? $brand->own_handles : [];
        $normalized = [];

        foreach ($platforms as $platform) {
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

        foreach (
            TrackedAccount::query()
                ->where('user_id', $userId)
                ->get(['platform', 'handle']) as $account
        ) {
            $keys["{$account->platform->value}:{$account->handle}"] = true;
        }

        return $keys;
    }

    private function looksLikeOwnBrand(BrandProfile $brand, string $handle, string $name): bool
    {
        $brandSlug = Str::slug($brand->name);
        $handleSlug = Str::slug($handle);
        $nameSlug = Str::slug($name);

        if ($brandSlug === '' || strlen($brandSlug) < 3) {
            return false;
        }

        return $handleSlug === $brandSlug
            || $nameSlug === $brandSlug
            || str_contains($handleSlug, $brandSlug)
            || str_contains($nameSlug, $brandSlug);
    }

    private function defaultUrl(Platform $platform, string $handle): string
    {
        return match ($platform) {
            Platform::Instagram => "https://www.instagram.com/{$handle}/",
            Platform::TikTok => "https://www.tiktok.com/@{$handle}",
            Platform::Youtube => "https://www.youtube.com/@{$handle}",
            Platform::LinkedIn => "https://www.linkedin.com/in/{$handle}",
            Platform::Facebook => "https://www.facebook.com/{$handle}",
        };
    }

    /**
     * Decode model chat text into an associative array. Tolerates markdown fences,
     * leading prose, and truncated trailing braces when possible.
     *
     * @return array<string, mixed>|null
     */
    public function decodeModelJson(string $text): ?array
    {
        $candidate = $this->extractJson($text);
        $decoded = json_decode($candidate, true);

        if (is_array($decoded)) {
            return $decoded;
        }

        // Truncated object: close open braces/brackets if the start looks like JSON.
        $trimmed = ltrim($candidate);
        if ($trimmed !== '' && ($trimmed[0] === '{' || $trimmed[0] === '[')) {
            $openCurly = substr_count($trimmed, '{') - substr_count($trimmed, '}');
            $openSquare = substr_count($trimmed, '[') - substr_count($trimmed, ']');
            if ($openCurly > 0 || $openSquare > 0) {
                $repaired = rtrim($trimmed, ", \n\r\t");
                $repaired .= str_repeat(']', max(0, $openSquare));
                $repaired .= str_repeat('}', max(0, $openCurly));
                $decoded = json_decode($repaired, true);
                if (is_array($decoded)) {
                    return $decoded;
                }
            }
        }

        return null;
    }

    private function extractJson(string $text): string
    {
        $trimmed = trim($text);

        if ($trimmed === '') {
            return $trimmed;
        }

        // Strip common markdown fences: ```json ... ``` or ``` ... ```
        if (preg_match('/^```(?:json)?\s*(.*?)\s*```$/is', $trimmed, $fenced) === 1) {
            $trimmed = trim($fenced[1]);
        } elseif (preg_match('/```(?:json)?\s*(\{.*\}|\[.*\])\s*```/is', $trimmed, $fenced) === 1) {
            $trimmed = trim($fenced[1]);
        }

        if (($trimmed[0] ?? '') === '{' || ($trimmed[0] ?? '') === '[') {
            return $trimmed;
        }

        if (preg_match('/(\{.*\}|\[.*\])/s', $trimmed, $matches) === 1) {
            return $matches[1];
        }

        return $trimmed;
    }

    /**
     * Multi-seed discovery continues after vendor blips. TikHub/Apify HTTP 4xx
     * (e.g. search endpoint 404 for a noisy query) are expected soft failures -
     * warn instead of report() so production.ERROR stays reserved for surprises.
     *
     * @param  array<string, mixed>  $context
     */
    private function reportSoftSeedFailure(Throwable $exception, string $message, array $context = []): void
    {
        if ($this->isExpectedVendorClientFailure($exception)) {
            Log::warning($message, [
                ...$context,
                'error' => $exception->getMessage(),
            ]);

            return;
        }

        report($exception);
    }

    private function isExpectedVendorClientFailure(Throwable $exception): bool
    {
        $message = $exception->getMessage();

        if (preg_match('/TikHub request failed \((4\d\d)\)/', $message) === 1) {
            return true;
        }

        if (preg_match('/TikHub API error \((4\d\d)\)/', $message) === 1) {
            return true;
        }

        return false;
    }
}
