<?php

namespace App\Services\Dashboard;

/**
 * Standard Inertia card payload: {status, n, data, reason}.
 */
final class CardResult
{
    /**
     * @return array{status: string, n: int, data: mixed, reason: string|null}
     */
    public static function ok(mixed $data, int $n = 0): array
    {
        return [
            'status' => 'ok',
            'n' => $n,
            'data' => $data,
            'reason' => null,
        ];
    }

    /**
     * @return array{status: string, n: int, data: mixed, reason: string|null}
     */
    public static function insufficient(string $reason, int $n = 0, mixed $data = null): array
    {
        return [
            'status' => 'insufficient',
            'n' => $n,
            'data' => $data,
            'reason' => $reason,
        ];
    }

    /**
     * @return array{status: string, n: int, data: mixed, reason: string|null}
     */
    public static function empty(string $reason, int $n = 0, mixed $data = null): array
    {
        return [
            'status' => 'empty',
            'n' => $n,
            'data' => $data,
            'reason' => $reason,
        ];
    }
}
