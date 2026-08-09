<?php

namespace App\Jobs;

use App\Exceptions\InsufficientCreditsException;
use App\Exceptions\InsufficientInfluencerSuggestionsException;
use App\Exceptions\PlatformSubscriptionRequiredException;
use App\Models\BrandProfile;
use App\Models\User;
use App\Services\Billing\UsageBillingService;
use App\Services\Billing\VendorUsageCharger;
use App\Services\Influencers\InfluencerDiscoveryService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class FindInfluencersJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [15, 60];

    public int $timeout = 600;

    /**
     * @param  array{
     *     platforms: list<string>,
     *     language: string|null,
     *     min_followers: int|null,
     *     max_followers: int|null,
     *     brief: string
     * }  $filters
     */
    public function __construct(
        public int $userId,
        public string $runId,
        public array $filters,
    ) {}

    public function handle(
        InfluencerDiscoveryService $discovery,
        VendorUsageCharger $charger,
        UsageBillingService $billing,
    ): void {
        $existing = $this->payload();

        $this->putStatus([
            'status' => 'processing',
            'filters' => $this->filters,
            'brief' => $this->filters['brief'] ?? '',
            'suggestions' => is_array($existing['suggestions'] ?? null) ? $existing['suggestions'] : [],
            'decisions' => is_array($existing['decisions'] ?? null) ? $existing['decisions'] : [],
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
                'filters' => $this->filters,
                'brief' => $this->filters['brief'] ?? '',
                'suggestions' => [],
                'decisions' => [],
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
            $rows = $discovery->discover($brand, $this->filters, function (array $partial): void {
                $current = $this->payload();
                $this->putStatus([
                    'status' => 'processing',
                    'filters' => $this->filters,
                    'brief' => $this->filters['brief'] ?? '',
                    'suggestions' => $partial,
                    'decisions' => is_array($current['decisions'] ?? null) ? $current['decisions'] : [],
                    'error' => null,
                ]);
            });
        } catch (InsufficientInfluencerSuggestionsException $exception) {
            $this->chargeInfluencerFindVendors($user, $charger, $billing);
            $current = $this->payload();
            $this->putStatus([
                'status' => 'failed',
                'filters' => $this->filters,
                'brief' => $this->filters['brief'] ?? '',
                'suggestions' => $exception->suggestions !== [] ? $exception->suggestions : [],
                'decisions' => is_array($current['decisions'] ?? null) ? $current['decisions'] : [],
                'error' => $exception->getMessage(),
            ]);

            return;
        }

        $this->chargeInfluencerFindVendors($user, $charger, $billing);

        $current = $this->payload();

        $this->putStatus([
            'status' => 'completed',
            'filters' => $this->filters,
            'brief' => $this->filters['brief'] ?? '',
            'suggestions' => $rows,
            'decisions' => is_array($current['decisions'] ?? null) ? $current['decisions'] : [],
            'error' => null,
        ]);
    }

    private function chargeInfluencerFindVendors(
        User $user,
        VendorUsageCharger $charger,
        UsageBillingService $billing,
    ): void {
        $searchLimit = (int) config('snitch.influencer_find.search_limit', 12);
        $charger->chargeFirecrawl(
            user: $user,
            action: 'influencers.find',
            cogsUsd: $billing->estimateFirecrawlSearchUsd($searchLimit) * 3,
            meta: ['run_id' => $this->runId, 'kind' => 'search'],
            idempotencyKey: 'influencers.find.firecrawl:'.$this->runId,
        );
        $charger->chargeNanoGpt(
            user: $user,
            action: 'influencers.find',
            cogsUsd: $billing->estimateNanoGptChatUsd(
                null,
                null,
                (string) config('snitch.influencer_find.model'),
            ),
            meta: ['run_id' => $this->runId, 'kind' => 'propose'],
            idempotencyKey: 'influencers.find.nanogpt:'.$this->runId,
        );
        $charger->chargePulledApifyRuns($user, 'influencers.find');
    }

    public function failed(?Throwable $exception): void
    {
        Log::warning('FindInfluencersJob failed', [
            'user_id' => $this->userId,
            'run_id' => $this->runId,
            'error' => $exception?->getMessage(),
        ]);

        $existing = $this->payload();
        $partials = is_array($existing['suggestions'] ?? null) ? $existing['suggestions'] : [];

        if ($exception instanceof InsufficientInfluencerSuggestionsException && $exception->suggestions !== []) {
            $partials = $exception->suggestions;
        }

        $this->putStatus([
            'status' => 'failed',
            'filters' => $this->filters,
            'brief' => $this->filters['brief'] ?? '',
            'suggestions' => $partials,
            'decisions' => is_array($existing['decisions'] ?? null) ? $existing['decisions'] : [],
            'error' => $exception?->getMessage() ?: 'Unable to find influencers.',
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function putStatus(array $payload): void
    {
        Cache::put($this->cacheKey(), $payload, now()->addHours(2));

        if (in_array($payload['status'] ?? null, ['completed', 'failed'], true)) {
            Cache::put(self::latestCacheKeyFor($this->userId), $this->runId, now()->addHours(24));
            self::clearActive($this->userId, $this->runId);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(): array
    {
        $payload = Cache::get($this->cacheKey());

        return is_array($payload) ? $payload : [];
    }

    public static function cacheKeyFor(int $userId, string $runId): string
    {
        return "influencer-find:{$userId}:{$runId}";
    }

    public static function activeCacheKeyFor(int $userId): string
    {
        return "influencer-find-active:{$userId}";
    }

    public static function latestCacheKeyFor(int $userId): string
    {
        return "influencer-find-latest:{$userId}";
    }

    public static function clearActive(int $userId, ?string $runId = null): void
    {
        $activeId = Cache::get(self::activeCacheKeyFor($userId));

        if (! is_string($activeId)) {
            return;
        }

        if ($runId !== null && $activeId !== $runId) {
            return;
        }

        Cache::forget(self::activeCacheKeyFor($userId));
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function latestPayload(int $userId): ?array
    {
        $latestId = Cache::get(self::latestCacheKeyFor($userId));

        if (! is_string($latestId)) {
            return null;
        }

        $payload = Cache::get(self::cacheKeyFor($userId, $latestId));

        return is_array($payload) ? array_merge($payload, ['id' => $latestId]) : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function activePayload(int $userId): ?array
    {
        $activeId = Cache::get(self::activeCacheKeyFor($userId));

        if (! is_string($activeId)) {
            return null;
        }

        $payload = Cache::get(self::cacheKeyFor($userId, $activeId));

        return is_array($payload) ? array_merge($payload, ['id' => $activeId]) : null;
    }

    private function cacheKey(): string
    {
        return self::cacheKeyFor($this->userId, $this->runId);
    }
}
