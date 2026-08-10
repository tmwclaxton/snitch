<?php

namespace App\Mcp\Support;

use Illuminate\Support\Facades\Cache;

/**
 * Short in-request poll so MCP agents can often skip extra status rounds.
 */
class McpJobWait
{
    public const DEFAULT_SECONDS = 22;

    public const MAX_SECONDS = 45;

    /**
     * Poll a job cache key until status is terminal or the wait budget elapses.
     *
     * @param  list<string>  $terminalStatuses
     * @return array{payload: mixed, timed_out: bool, waited_seconds: int}
     */
    public static function untilTerminal(
        string $cacheKey,
        ?int $waitSeconds = null,
        array $terminalStatuses = ['completed', 'failed', 'done'],
        int $defaultSeconds = self::DEFAULT_SECONDS,
    ): array {
        $seconds = self::clamp($waitSeconds, $defaultSeconds);
        $payload = Cache::get($cacheKey);

        if ($seconds === 0) {
            return [
                'payload' => $payload,
                'timed_out' => ! self::isTerminal($payload, $terminalStatuses),
                'waited_seconds' => 0,
            ];
        }

        $deadline = microtime(true) + $seconds;

        while (microtime(true) < $deadline) {
            $payload = Cache::get($cacheKey);
            if (self::isTerminal($payload, $terminalStatuses)) {
                return [
                    'payload' => $payload,
                    'timed_out' => false,
                    'waited_seconds' => (int) max(0, (int) round($seconds - max(0, $deadline - microtime(true)))),
                ];
            }

            usleep(200_000);
        }

        $payload = Cache::get($cacheKey);

        return [
            'payload' => $payload,
            'timed_out' => ! self::isTerminal($payload, $terminalStatuses),
            'waited_seconds' => $seconds,
        ];
    }

    public static function clamp(?int $waitSeconds, int $defaultSeconds = self::DEFAULT_SECONDS): int
    {
        $value = $waitSeconds ?? $defaultSeconds;

        return max(0, min(self::MAX_SECONDS, $value));
    }

    /**
     * @param  list<string>  $terminalStatuses
     */
    public static function isTerminal(mixed $payload, array $terminalStatuses = ['completed', 'failed', 'done']): bool
    {
        if (! is_array($payload)) {
            return false;
        }

        $status = $payload['status'] ?? null;

        return is_string($status) && in_array($status, $terminalStatuses, true);
    }
}
