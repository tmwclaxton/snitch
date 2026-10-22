<?php

namespace App\Support;

/**
 * Sentence-aware caption clipping. Never cut mid-word when truncating.
 */
class CaptionExcerpt
{
    public static function limit(?string $caption, int $maxChars = 2000): ?string
    {
        $text = trim((string) $caption);

        if ($text === '') {
            return null;
        }

        if (mb_strlen($text) <= $maxChars) {
            return $text;
        }

        $window = mb_substr($text, 0, $maxChars + 1);
        $minKeep = (int) floor($maxChars * 0.45);
        $bestEnd = null;

        $length = mb_strlen($window);
        for ($i = 0; $i < $length; $i++) {
            $char = mb_substr($window, $i, 1);
            if ($char !== '.' && $char !== '!' && $char !== '?') {
                continue;
            }

            $next = $i + 1 < $length ? mb_substr($window, $i + 1, 1) : '';
            if ($next !== '' && ! preg_match('/\s/u', $next)) {
                continue;
            }

            if ($i + 1 >= $minKeep) {
                $bestEnd = $i + 1;
            }
        }

        if ($bestEnd !== null) {
            return trim(mb_substr($window, 0, $bestEnd));
        }

        return self::wordBoundary($text, $maxChars);
    }

    private static function wordBoundary(string $text, int $maxChars): string
    {
        $slice = mb_substr($text, 0, $maxChars);
        $lastSpace = mb_strrpos($slice, ' ');

        if ($lastSpace !== false && $lastSpace >= (int) floor($maxChars * 0.5)) {
            return rtrim(mb_substr($slice, 0, $lastSpace), '.,;:').'…';
        }

        return rtrim($slice).'…';
    }
}
