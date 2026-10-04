<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\Brief\DailyBriefGenerator;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class GenerateDailyBriefJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    /** @var list<int> */
    public array $backoff = [10, 60];

    public int $timeout = 180;

    public int $uniqueFor = 600;

    public function __construct(
        public int $userId,
        public ?string $date = null,
        public bool $force = false,
    ) {}

    public function uniqueId(): string
    {
        return $this->userId.':'.($this->date ?? 'today');
    }

    public static function queueFor(int $userId, ?string $date = null, bool $force = false): void
    {
        Cache::put(self::cacheKey($userId, $date), ['status' => 'queued'], now()->addHour());
        self::dispatch($userId, $date, $force);
    }

    public static function isActiveFor(int $userId, ?string $date = null): bool
    {
        $payload = Cache::get(self::cacheKey($userId, $date));

        return is_array($payload) && in_array($payload['status'] ?? null, ['queued', 'processing'], true);
    }

    public function handle(DailyBriefGenerator $generator): void
    {
        Cache::put(self::cacheKey($this->userId, $this->date), ['status' => 'processing'], now()->addHour());

        $user = User::query()->find($this->userId);

        if ($user === null) {
            Cache::forget(self::cacheKey($this->userId, $this->date));

            return;
        }

        $date = $this->date !== null
            ? CarbonImmutable::parse($this->date, 'Europe/London')->startOfDay()
            : $generator->briefDate();

        try {
            $generator->generate($user, $date, $this->force);
            Cache::put(self::cacheKey($this->userId, $this->date), ['status' => 'completed'], now()->addMinutes(10));
        } catch (Throwable $exception) {
            Cache::put(self::cacheKey($this->userId, $this->date), [
                'status' => 'failed',
                'error' => $exception->getMessage(),
            ], now()->addMinutes(10));

            throw $exception;
        }
    }

    public function failed(?Throwable $exception): void
    {
        Cache::put(self::cacheKey($this->userId, $this->date), [
            'status' => 'failed',
            'error' => $exception?->getMessage(),
        ], now()->addMinutes(10));

        Log::warning('GenerateDailyBriefJob failed', [
            'user_id' => $this->userId,
            'date' => $this->date,
            'error' => $exception?->getMessage(),
        ]);
    }

    private static function cacheKey(int $userId, ?string $date): string
    {
        return 'daily-brief-job:'.$userId.':'.($date ?? 'today');
    }
}
