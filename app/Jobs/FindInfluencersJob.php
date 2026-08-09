<?php

namespace App\Jobs;

use App\Exceptions\InsufficientInfluencerSuggestionsException;
use App\Models\BrandProfile;
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

    public function handle(InfluencerDiscoveryService $discovery): void
    {
        $existing = $this->payload();

        $this->putStatus([
            'status' => 'processing',
            'filters' => $this->filters,
            'brief' => $this->filters['brief'] ?? '',
            'suggestions' => is_array($existing['suggestions'] ?? null) ? $existing['suggestions'] : [],
            'decisions' => is_array($existing['decisions'] ?? null) ? $existing['decisions'] : [],
            'error' => null,
        ]);

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
