<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Throwable;

/**
 * Normalise messy social scrape dates (Unix, ISO, relative English/Chinese, 年月日).
 */
final class SocialDateParser
{
    public static function toIso8601(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_string($value)) {
            $value = trim($value);
        }

        if ($value === '' || $value === false) {
            return null;
        }

        if (is_numeric($value)) {
            $timestamp = (int) $value;

            if ($timestamp > 10_000_000_000) {
                $timestamp = (int) floor($timestamp / 1000);
            }

            if ($timestamp <= 0) {
                return null;
            }

            try {
                return CarbonImmutable::createFromTimestamp($timestamp)->toIso8601String();
            } catch (Throwable) {
                return null;
            }
        }

        if (! is_string($value)) {
            return null;
        }

        $chineseAbsolute = self::parseChineseAbsolute($value);
        if ($chineseAbsolute !== null) {
            return $chineseAbsolute;
        }

        $relative = self::parseRelative($value);
        if ($relative !== null) {
            return $relative;
        }

        try {
            return CarbonImmutable::parse($value)->toIso8601String();
        } catch (Throwable) {
            return null;
        }
    }

    private static function parseChineseAbsolute(string $value): ?string
    {
        if (preg_match('/^(\d{4})\s*年\s*(\d{1,2})\s*月\s*(\d{1,2})\s*日$/u', $value, $matches) !== 1) {
            return null;
        }

        try {
            return CarbonImmutable::create(
                (int) $matches[1],
                (int) $matches[2],
                (int) $matches[3],
                0,
                0,
                0,
                'UTC',
            )->toIso8601String();
        } catch (Throwable) {
            return null;
        }
    }

    private static function parseRelative(string $value): ?string
    {
        $normalized = strtolower(trim($value));

        if (preg_match('/^(\d+)\s*(second|minute|hour|day|week|month|year)s?\s+ago$/i', $normalized, $matches) === 1) {
            return self::subUnits((int) $matches[1], strtolower($matches[2]));
        }

        if (preg_match('/^(\d+)\s*秒前$/u', $value, $matches) === 1) {
            return self::subUnits((int) $matches[1], 'second');
        }

        if (preg_match('/^(\d+)\s*分钟前$/u', $value, $matches) === 1) {
            return self::subUnits((int) $matches[1], 'minute');
        }

        if (preg_match('/^(\d+)\s*小时前$/u', $value, $matches) === 1) {
            return self::subUnits((int) $matches[1], 'hour');
        }

        if (preg_match('/^(\d+)\s*天前$/u', $value, $matches) === 1) {
            return self::subUnits((int) $matches[1], 'day');
        }

        if (preg_match('/^(\d+)\s*周前$/u', $value, $matches) === 1) {
            return self::subUnits((int) $matches[1], 'week');
        }

        if (preg_match('/^(\d+)\s*个月前$/u', $value, $matches) === 1) {
            return self::subUnits((int) $matches[1], 'month');
        }

        if (preg_match('/^(\d+)\s*年前$/u', $value, $matches) === 1) {
            return self::subUnits((int) $matches[1], 'year');
        }

        return null;
    }

    private static function subUnits(int $amount, string $unit): ?string
    {
        if ($amount < 0) {
            return null;
        }

        $now = CarbonImmutable::now('UTC');

        return match ($unit) {
            'second' => $now->subSeconds($amount)->toIso8601String(),
            'minute' => $now->subMinutes($amount)->toIso8601String(),
            'hour' => $now->subHours($amount)->toIso8601String(),
            'day' => $now->subDays($amount)->toIso8601String(),
            'week' => $now->subWeeks($amount)->toIso8601String(),
            'month' => $now->subMonthsNoOverflow($amount)->toIso8601String(),
            'year' => $now->subYearsNoOverflow($amount)->toIso8601String(),
            default => null,
        };
    }
}
