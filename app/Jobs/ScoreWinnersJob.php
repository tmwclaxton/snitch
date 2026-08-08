<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\Winners\WinnerScorer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class ScoreWinnersJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    /** @var list<int> */
    public array $backoff = [1, 5];

    public int $timeout = 300;

    public function __construct(
        public int $userId,
        public string $runId = '',
    ) {
        if ($this->runId === '') {
            $this->runId = (string) Str::uuid();
        }
    }

    public static function queueFor(int $userId): string
    {
        $runId = (string) Str::uuid();

        Cache::put(self::cacheKeyFor($userId, $runId), [
            'status' => 'pending',
            'error' => null,
            'winner_count' => null,
        ], now()->addHour());

        Cache::put(self::activeCacheKeyFor($userId), $runId, now()->addHour());

        self::dispatch($userId, $runId);

        return $runId;
    }

    public function handle(WinnerScorer $scorer): void
    {
        $this->putStatus([
            'status' => 'processing',
            'error' => null,
            'winner_count' => null,
        ]);

        $user = User::query()->find($this->userId);

        if ($user === null) {
            $this->putStatus([
                'status' => 'failed',
                'error' => 'User not found.',
                'winner_count' => null,
            ]);

            return;
        }

        $insights = $scorer->rescoreUser($user);

        $this->putStatus([
            'status' => 'completed',
            'error' => null,
            'winner_count' => $insights->count(),
        ]);
    }

    public function failed(?Throwable $exception): void
    {
        Log::warning('ScoreWinnersJob failed', [
            'user_id' => $this->userId,
            'run_id' => $this->runId,
            'error' => $exception?->getMessage(),
        ]);

        $this->putStatus([
            'status' => 'failed',
            'error' => $exception?->getMessage() ?: 'Unable to rescore winners.',
            'winner_count' => null,
        ]);
    }

    /**
     * @return array{id: string, status: string}|null
     */
    public static function activeRunFor(int $userId): ?array
    {
        $runId = Cache::get(self::activeCacheKeyFor($userId));

        if (! is_string($runId) || $runId === '') {
            return null;
        }

        $payload = self::statusFor($userId, $runId);

        if ($payload === null) {
            return null;
        }

        if (! in_array($payload['status'], ['pending', 'processing'], true)) {
            return null;
        }

        return [
            'id' => $runId,
            'status' => $payload['status'],
        ];
    }

    /**
     * @return array{status: string, error: ?string, winner_count: ?int}|null
     */
    public static function statusFor(int $userId, string $runId): ?array
    {
        $payload = Cache::get(self::cacheKeyFor($userId, $runId));

        if (! is_array($payload)) {
            return null;
        }

        $status = $payload['status'] ?? null;

        if (! is_string($status) || $status === '') {
            return null;
        }

        $winnerCount = $payload['winner_count'] ?? null;

        return [
            'status' => $status,
            'error' => isset($payload['error']) && is_string($payload['error']) ? $payload['error'] : null,
            'winner_count' => is_int($winnerCount) ? $winnerCount : null,
        ];
    }

    public static function cacheKeyFor(int $userId, string $runId): string
    {
        return "winners-rescore:{$userId}:{$runId}";
    }

    public static function activeCacheKeyFor(int $userId): string
    {
        return "winners-rescore-active:{$userId}";
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
     * @param  array{status: string, error: ?string, winner_count: ?int}  $payload
     */
    private function putStatus(array $payload): void
    {
        Cache::put($this->cacheKey(), $payload, now()->addHour());
        Cache::put(self::activeCacheKeyFor($this->userId), $this->runId, now()->addHour());

        if (in_array($payload['status'], ['completed', 'failed'], true)) {
            self::clearActive($this->userId, $this->runId);
        }
    }

    private function cacheKey(): string
    {
        return self::cacheKeyFor($this->userId, $this->runId);
    }
}
