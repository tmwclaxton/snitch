<?php

namespace App\Jobs;

use App\Models\BrandProfile;
use App\Services\Competitors\CompetitorSuggestionService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class SuggestCompetitorsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    /** @var list<int> */
    public array $backoff = [1, 5];

    public int $timeout = 180;

    public function __construct(
        public int $userId,
        public string $suggestId,
    ) {}

    public function handle(CompetitorSuggestionService $suggestions): void
    {
        $this->putStatus([
            'status' => 'processing',
            'suggestions' => null,
            'error' => null,
        ]);

        $brand = BrandProfile::query()
            ->where('user_id', $this->userId)
            ->first();

        if ($brand === null) {
            throw new \RuntimeException('Brand profile not found.');
        }

        $rows = $suggestions->suggest($brand);

        $this->putStatus([
            'status' => 'completed',
            'suggestions' => $rows,
            'error' => null,
        ]);
    }

    public function failed(?Throwable $exception): void
    {
        Log::warning('SuggestCompetitorsJob failed', [
            'user_id' => $this->userId,
            'suggest_id' => $this->suggestId,
            'error' => $exception?->getMessage(),
        ]);

        $this->putStatus([
            'status' => 'failed',
            'suggestions' => null,
            'error' => $exception?->getMessage() ?: 'Unable to suggest competitors.',
        ]);
    }

    /**
     * @param  array{status: string, suggestions: ?list<array<string, mixed>>, error: ?string}  $payload
     */
    private function putStatus(array $payload): void
    {
        Cache::put($this->cacheKey(), $payload, now()->addMinutes(15));

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
