<?php

namespace App\Services\Competitors;

use App\Enums\Platform;
use App\Models\BrandProfile;
use App\Models\TrackedAccount;
use App\Services\Analysis\NanoGptClient;
use App\Services\Apify\PlatformAdapterManager;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class CompetitorSuggestionService
{
    public function __construct(
        public NanoGptClient $nanoGpt,
        public PlatformAdapterManager $adapters,
    ) {}

    /**
     * @return list<array{platform: string, handle: string, url: string, display_name: string, avatar: string|null}>
     */
    public function suggest(BrandProfile $brand): array
    {
        $candidates = $this->propose($brand);
        $verified = $this->verify($candidates, $brand);

        $max = max(1, (int) config('snitch.competitor_suggest.max_suggestions', 8));

        return array_slice($verified, 0, $max);
    }

    /**
     * @return list<array{name: string, platform: string, handle: string}>
     */
    public function propose(BrandProfile $brand): array
    {
        if ((string) config('snitch.nanogpt.api_key') === '') {
            throw new RuntimeException('NANOGPT_API_KEY is not configured.');
        }

        $maxCandidates = max(1, (int) config('snitch.competitor_suggest.max_candidates', 12));
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
                'content' => 'You suggest real public competitor brands for social content tracking. Reply with JSON only. Never invent placeholder handles like *_local, *tips, or slug-derived fakes. Never suggest the brand itself or its own handles. Prefer real org Facebook pages and Instagram accounts that exist publicly. Omit a platform when you are unsure of a real handle. Do not invent TikToks. Niche rivals only - not lifestyle, meme, or unrelated accounts.',
            ],
            [
                'role' => 'user',
                'content' => $this->proposeUserPrompt($brand, $ownSummary, $platforms, $maxCandidates),
            ],
        ], (string) config('snitch.competitor_suggest.model'), [
            'temperature' => (float) config('snitch.competitor_suggest.temperature', 0.3),
            'max_tokens' => (int) config('snitch.competitor_suggest.max_tokens', 1200),
            'response_format' => ['type' => 'json_object'],
        ]);

        $text = $this->nanoGpt->extractAssistantText($response);
        $payload = json_decode($this->extractJson($text), true);

        if (! is_array($payload)) {
            throw new RuntimeException('Competitor suggestion model returned invalid JSON.');
        }

        return $this->normalizeCandidates($payload, $platforms, $maxCandidates);
    }

    /**
     * @param  list<array{name: string, platform: string, handle: string}>  $candidates
     * @return list<array{platform: string, handle: string, url: string, display_name: string, avatar: string|null}>
     */
    public function verify(array $candidates, BrandProfile $brand): array
    {
        $ownHandles = $this->normalizedOwnHandles($brand);
        $tracked = $this->trackedKeys($brand->user_id);
        $seen = [];
        $suggestions = [];
        $max = max(1, (int) config('snitch.competitor_suggest.max_suggestions', 8));

        foreach ($candidates as $candidate) {
            if (count($suggestions) >= $max) {
                break;
            }

            $platform = $candidate['platform'];
            $handle = $candidate['handle'];
            $key = "{$platform}:{$handle}";

            if (isset($seen[$key]) || isset($tracked[$key])) {
                continue;
            }

            if (($ownHandles[$platform] ?? null) === $handle) {
                continue;
            }

            if ($this->looksLikeOwnBrand($brand, $handle, $candidate['name'])) {
                continue;
            }

            $seen[$key] = true;

            try {
                $profile = $this->adapters->for($platform)->resolveProfile($handle);
            } catch (Throwable) {
                continue;
            }

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

            $displayName = $candidate['name'] !== ''
                ? $candidate['name']
                : (filled($profile['display_name'] ?? null)
                    ? (string) $profile['display_name']
                    : $resolvedHandle);

            $suggestions[] = [
                'platform' => $platform,
                'handle' => $resolvedHandle,
                'url' => filled($profile['url'] ?? null)
                    ? (string) $profile['url']
                    : $this->defaultUrl(Platform::from($platform), $resolvedHandle),
                'display_name' => $displayName,
                'avatar' => filled($profile['avatar'] ?? null) ? (string) $profile['avatar'] : null,
            ];
        }

        return $suggestions;
    }

    /**
     * @param  list<string>  $platforms
     * @return list<array{name: string, platform: string, handle: string}>
     */
    private function normalizeCandidates(array $payload, array $platforms, int $maxCandidates): array
    {
        $rows = $payload['competitors'] ?? $payload['suggestions'] ?? $payload;

        if (! is_array($rows)) {
            return [];
        }

        $platformSet = array_fill_keys($platforms, true);
        $platformPriority = $this->platformPriority($platforms);
        $orgHandles = [];
        $orgCount = 0;

        foreach ($rows as $row) {
            if (! is_array($row) || $orgCount >= $maxCandidates) {
                break;
            }

            $name = trim((string) ($row['name'] ?? $row['org'] ?? $row['brand'] ?? ''));
            $handles = $row['handles'] ?? null;
            $parsed = [];

            if (is_array($handles)) {
                foreach ($handles as $platform => $handle) {
                    if (! is_string($platform) || ! isset($platformSet[$platform])) {
                        continue;
                    }

                    $normalized = $this->normalizeHandle($handle);

                    if ($normalized === null) {
                        continue;
                    }

                    $parsed[$platform] = [
                        'name' => $name,
                        'platform' => $platform,
                        'handle' => $normalized,
                    ];
                }
            } else {
                $platform = strtolower(trim((string) ($row['platform'] ?? '')));
                $handle = $this->normalizeHandle($row['handle'] ?? null);

                if (isset($platformSet[$platform]) && $handle !== null) {
                    $parsed[$platform] = [
                        'name' => $name,
                        'platform' => $platform,
                        'handle' => $handle,
                    ];
                }
            }

            if ($parsed === []) {
                continue;
            }

            $orgHandles[] = $parsed;
            $orgCount++;
        }

        // One best handle per org (Facebook > Instagram > ...) keeps resolves fast and niches diverse.
        $candidates = [];

        foreach ($orgHandles as $parsed) {
            foreach ($platformPriority as $platform) {
                if (isset($parsed[$platform])) {
                    $candidates[] = $parsed[$platform];
                    break;
                }
            }
        }

        $maxResolves = max(
            $maxCandidates,
            (int) config('snitch.competitor_suggest.max_resolves', 16),
        );

        return array_slice($candidates, 0, $maxResolves);
    }

    /**
     * @param  list<string>  $platforms
     * @return list<string>
     */
    private function platformPriority(array $platforms): array
    {
        $preferred = ['facebook', 'instagram', 'linkedin', 'tiktok'];
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

    private function normalizeHandle(mixed $handle): ?string
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

        return Str::limit($value, 80, '');
    }

    /**
     * @return array<string, string>
     */
    private function normalizedOwnHandles(BrandProfile $brand): array
    {
        $handles = is_array($brand->own_handles) ? $brand->own_handles : [];
        $normalized = [];

        foreach ($this->configuredPlatforms() as $platform) {
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
     */
    private function proposeUserPrompt(BrandProfile $brand, string $ownSummary, array $platforms, int $maxCandidates): string
    {
        $platformList = implode(', ', $platforms);
        $website = filled($brand->website) ? $brand->website : 'unknown';
        $description = filled($brand->description) ? $brand->description : 'unknown';

        return <<<PROMPT
Brand name: {$brand->name}
Website: {$website}
Description: {$description}
Own handles (do not suggest these): {$ownSummary}

Suggest {$maxCandidates} distinct niche competitor organizations whose social content is worth tracking.
Focus on direct rivals and adjacent tools in the same category as the brand (for grant/funding SaaS: grant databases, fellowship platforms, foundation directories, grant-management / CRM tools, proposal platforms - not random lifestyle brands).
Prefer Facebook page vanity names and Instagram usernames you are confident exist. Omit unsure handles (use null). Do not invent TikToks. LinkedIn company slugs only when certain.

Return JSON shaped as:
{
  "competitors": [
    {
      "name": "Org Name",
      "handles": {
        "facebook": "page_handle_or_null",
        "instagram": "handle_or_null",
        "linkedin": "company_slug_or_null",
        "tiktok": null
      }
    }
  ]
}

Only include platforms from: {$platformList}.
Return about {$maxCandidates} organizations (not {$maxCandidates} handles). Mix platforms only when real handles exist.
PROMPT;
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
        };
    }
}
