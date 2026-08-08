<?php

namespace App\Support;

use Throwable;

class SafeExceptionMessage
{
    /**
     * User-safe exception text with secrets stripped from URLs and bearer tokens.
     */
    public static function forUsers(?Throwable $exception, string $fallback = 'Something went wrong.'): string
    {
        $message = trim((string) ($exception?->getMessage() ?: $fallback));

        if ($message === '') {
            $message = $fallback;
        }

        $message = preg_replace('/([?&]token=)[^&\s\"\']+/i', '$1[redacted]', $message) ?? $message;
        $message = preg_replace('/(Bearer\s+)[A-Za-z0-9._\-+=\/]+/i', '$1[redacted]', $message) ?? $message;
        $message = preg_replace('/(api[_-]?key=)[^&\s\"\']+/i', '$1[redacted]', $message) ?? $message;

        return mb_substr($message, 0, 1000);
    }
}
