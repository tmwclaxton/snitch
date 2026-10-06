<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * Last-seen timestamps for the scheduler and each scheduled data job, kept in the
 * shared cache (Redis in production) so they survive blue/green deploys and can be
 * read by snitch:audit.
 */
class ScheduleHeartbeat
{
    public const TICK = 'scheduler';

    private const PREFIX = 'snitch:schedule-heartbeat:';

    public static function mark(string $name, string $outcome = 'success'): void
    {
        Cache::forever(self::PREFIX.$name.':'.$outcome, now()->toIso8601String());
    }

    /**
     * Record a scheduled command from inside handle() so a run is stored even
     * when Laravel's onSuccess hook does not fire after a deploy.
     */
    public static function record(string $name, int $exitCode): int
    {
        self::mark($name, $exitCode === 0 ? 'success' : 'failure');

        return $exitCode;
    }

    public static function last(string $name, string $outcome = 'success'): ?CarbonImmutable
    {
        $value = Cache::get(self::PREFIX.$name.':'.$outcome);

        return is_string($value) && $value !== '' ? CarbonImmutable::parse($value) : null;
    }
}
