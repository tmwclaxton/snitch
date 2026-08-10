<?php

namespace App\Jobs;

use App\Exceptions\InsufficientCompetitorSuggestionsException;
use App\Exceptions\InsufficientCreditsException;
use App\Exceptions\PlatformSubscriptionRequiredException;
use App\Models\BrandProfile;
use App\Models\User;
use App\Services\Billing\UsageBillingService;
use App\Services\Billing\VendorUsageCharger;
use App\Services\Competitors\CompetitorSuggestionService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class SuggestCompetitorsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [15, 60];

    public int $timeout = 600;

    public function __construct(
        public int $userId,
        public string $suggestId,
    ) {}

    public function handle(
        CompetitorSuggestionService $suggestions,
        VendorUsageCharger $charger,
        UsageBillingService $billing,
    ): void {
        $this->putStatus([
            'status' => 'processing',
            'suggestions' => null,
            'error' => null,
        ]);

        $user = User::query()->find($this->userId);

        if ($user === null) {
            throw new \RuntimeException('User not found.');
        }

        try {
            $charger->assertCanRun($user);
        } catch (PlatformSubscriptionRequiredException|InsufficientCreditsException $e) {
            $this->putStatus([
                'status' => 'failed',
                'suggestions' => null,
                'error' => $e->getMessage(),
            ]);

            return;
        }

        $brand = BrandProfile::query()
            ->where('user_id', $this->userId)
            ->first();

        if ($brand === null) {
            throw new \RuntimeException('Brand profile not found.');
        }

        try {
            $rows = $suggestions->suggest($brand, function (array $partial) {
                $this->putStatus([
                    'status' => 'processing',
                    'suggestions' => $partial,
                    'error' => null,
                ]);
            });
        } catch (InsufficientCompetitorSuggestionsException $exception) {
            $this->chargeCompetitorSuggestVendors($user, $charger, $billing);
            $this->putStatus([
                'status' => 'failed',
                'suggestions' => $exception->suggestions !== [] ? $exception->suggestions : null,
                'error' => $exception->getMessage(),
            ]);

            return;
        }

        $this->chargeCompetitorSuggestVendors($user, $charger, $billing);

        $this->putStatus([
            'status' => 'completed',
            'suggestions' => $rows,
            'error' => null,
        ]);
    }

    private function chargeCompetitorSuggestVendors(
        User $user,
        VendorUsageCharger $charger,
        UsageBillingService $billing,
    ): void {
        $searchLimit = (int) config('snitch.competitor_suggest.search_limit', 8);
        $charger->chargeFirecrawl(
            user: $user,
            action: 'competitors.suggest',
            cogsUsd: $billing->estimateFirecrawlSearchUsd($searchLimit) * 3,
            meta: ['suggest_id' => $this->suggestId, 'kind' => 'search'],
            idempotencyKey: 'competitors.suggest.firecrawl:'.$this->suggestId,
        );
        $charger->chargeNanoGpt(
            user: $user,
            action: 'competitors.suggest',
            cogsUsd: $billing->estimateNanoGptChatUsd(
                null,
                null,
                (string) config('snitch.competitor_suggest.model'),
            ),
            meta: ['suggest_id' => $this->suggestId, 'kind' => 'propose'],
            idempotencyKey: 'competitors.suggest.nanogpt:'.$this->suggestId,
        );
        $charger->chargePulledApifyRuns($user, 'competitors.suggest');
        $charger->chargePulledTikHubRuns($user, 'competitors.suggest');
    }

    public function failed(?Throwable $exception): void
    {
        Log::warning('SuggestCompetitorsJob failed', [
            'user_id' => $this->userId,
            'suggest_id' => $this->suggestId,
            'error' => $exception?->getMessage(),
        ]);

        $existing = Cache::get($this->cacheKey());
        $partials = is_array($existing) && is_array($existing['suggestions'] ?? null)
            ? $existing['suggestions']
            : null;

        if ($exception instanceof InsufficientCompetitorSuggestionsException && $exception->suggestions !== []) {
            $partials = $exception->suggestions;
        }

        $this->putStatus([
            'status' => 'failed',
            'suggestions' => $partials,
            'error' => $exception?->getMessage() ?: 'Unable to suggest competitors.',
        ]);
    }

    /**
     * @param  array{status: string, suggestions: ?list<array<string, mixed>>, error: ?string}  $payload
     */
    private function putStatus(array $payload): void
    {
        Cache::put($this->cacheKey(), $payload, now()->addHours(2));

        if ($payload['status'] === 'completed') {
            Cache::put(self::latestCacheKeyFor($this->userId), $this->suggestId, now()->addHours(2));
        }

        if (in_array($payload['status'], ['completed', 'failed'], true)) {
            self::clearActive($this->userId, $this->suggestId);
        }
    }

    public static function cacheKeyFor(int $userId, string $suggestId): string
    {
        return "competitor-suggest:{$userId}:{$suggestId}";
    }

    public static function activeCacheKeyFor(int $userId): string
    {
        return "competitor-suggest-active:{$userId}";
    }

    public static function latestCacheKeyFor(int $userId): string
    {
        return "competitor-suggest-latest:{$userId}";
    }

    /**
     * Seed the poll cache + active pointer used by the Competitors page and MCP status tools.
     * Web and MCP must both call this so Inertia can show a running suggest job.
     */
    public static function beginRun(int $userId, string $suggestId): void
    {
        self::clearLatest($userId);

        Cache::put(self::cacheKeyFor($userId, $suggestId), [
            'status' => 'pending',
            'suggestions' => null,
            'error' => null,
        ], now()->addHours(2));

        Cache::put(
            self::activeCacheKeyFor($userId),
            $suggestId,
            now()->addHours(2),
        );
    }

    public static function clearLatest(int $userId): void
    {
        $latestId = Cache::get(self::latestCacheKeyFor($userId));

        if (is_string($latestId)) {
            Cache::forget(self::cacheKeyFor($userId, $latestId));
        }

        Cache::forget(self::latestCacheKeyFor($userId));
    }

    /**
     * Drop confirmed/tracked rows from the persisted suggestion set so they stay gone after reload.
     *
     * @param  list<array{platform?: string, handle?: string}|string>  $keys  "platform:handle" or rows with those fields
     */
    public static function pruneLatestSuggestions(int $userId, array $keys): void
    {
        $remove = [];

        foreach ($keys as $key) {
            if (is_string($key) && $key !== '') {
                $remove[strtolower($key)] = true;

                continue;
            }

            if (! is_array($key)) {
                continue;
            }

            $platform = strtolower(trim((string) ($key['platform'] ?? '')));
            $handle = ltrim(strtolower(trim((string) ($key['handle'] ?? ''))), '@');

            if ($platform === '' || $handle === '') {
                continue;
            }

            $remove["{$platform}:{$handle}"] = true;
        }

        if ($remove === []) {
            return;
        }

        $latestId = Cache::get(self::latestCacheKeyFor($userId));

        if (! is_string($latestId)) {
            return;
        }

        $payload = Cache::get(self::cacheKeyFor($userId, $latestId));

        if (! is_array($payload) || ($payload['status'] ?? null) !== 'completed') {
            return;
        }

        $suggestions = is_array($payload['suggestions'] ?? null) ? $payload['suggestions'] : [];
        $kept = [];

        foreach ($suggestions as $row) {
            if (! is_array($row)) {
                continue;
            }

            $platform = strtolower(trim((string) ($row['platform'] ?? '')));
            $handle = ltrim(strtolower(trim((string) ($row['handle'] ?? ''))), '@');
            $composite = "{$platform}:{$handle}";

            if ($platform === '' || $handle === '' || isset($remove[$composite])) {
                continue;
            }

            $kept[] = $row;
        }

        if ($kept === []) {
            self::clearLatest($userId);

            return;
        }

        $payload['suggestions'] = $kept;
        Cache::put(self::cacheKeyFor($userId, $latestId), $payload, now()->addHours(2));
    }

    public static function clearActive(int $userId, ?string $suggestId = null): void
    {
        $activeId = Cache::get(self::activeCacheKeyFor($userId));

        if (! is_string($activeId)) {
            return;
        }

        if ($suggestId !== null && $activeId !== $suggestId) {
            return;
        }

        Cache::forget(self::activeCacheKeyFor($userId));
    }

    private function cacheKey(): string
    {
        return self::cacheKeyFor($this->userId, $this->suggestId);
    }
}
