<?php

namespace App\Jobs;

use App\Exceptions\InsufficientCreditsException;
use App\Exceptions\PlatformSubscriptionRequiredException;
use App\Models\User;
use App\Services\Brief\WeeklyBriefGenerator;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class GenerateWeeklyBriefJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    /** @var list<int> */
    public array $backoff = [10, 60];

    public int $timeout = 120;

    public int $uniqueFor = 600;

    public function __construct(
        public int $userId,
        public bool $force = false,
    ) {}

    public function uniqueId(): string
    {
        return (string) $this->userId;
    }

    public static function queueFor(int $userId, bool $force = false): void
    {
        Cache::put(self::cacheKey($userId), ['status' => 'queued'], now()->addHour());
        self::dispatch($userId, $force);
    }

    public static function isActiveFor(int $userId): bool
    {
        $payload = Cache::get(self::cacheKey($userId));

        return is_array($payload) && in_array($payload['status'] ?? null, ['queued', 'processing'], true);
    }

    public function handle(WeeklyBriefGenerator $generator): void
    {
        Cache::put(self::cacheKey($this->userId), ['status' => 'processing'], now()->addHour());

        $user = User::query()->find($this->userId);

        if ($user === null) {
            Cache::forget(self::cacheKey($this->userId));

            return;
        }

        try {
            $generator->generate($user, force: $this->force);
            Cache::put(self::cacheKey($this->userId), ['status' => 'completed'], now()->addMinutes(10));
        } catch (PlatformSubscriptionRequiredException|InsufficientCreditsException $e) {
            Cache::put(self::cacheKey($this->userId), [
                'status' => 'failed',
                'error' => $e->getMessage(),
            ], now()->addMinutes(10));
        }
    }

    public function failed(?Throwable $exception): void
    {
        Cache::put(self::cacheKey($this->userId), [
            'status' => 'failed',
            'error' => $exception?->getMessage(),
        ], now()->addMinutes(10));

        Log::warning('GenerateWeeklyBriefJob failed', [
            'user_id' => $this->userId,
            'error' => $exception?->getMessage(),
        ]);
    }

    private static function cacheKey(int $userId): string
    {
        return 'weekly-brief-job:'.$userId;
    }
}
