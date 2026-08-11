<?php

namespace App\Support;

use App\Enums\Platform;

/**
 * Mechanical handle hygiene for tracked accounts and confirm paths.
 */
final class SocialHandle
{
    /** @var list<string> */
    private const WEAK_TOKENS = [
        'about',
        'channel',
        'channels',
        'content',
        'explore',
        'feed',
        'help',
        'home',
        'login',
        'official',
        'page',
        'pages',
        'profile',
        'reels',
        'search',
        'settings',
        'share',
        'shorts',
        'signup',
        'support',
        'user',
        'users',
        'video',
        'videos',
        'watch',
        'www',
    ];

    public static function normalize(mixed $handle): ?string
    {
        if (! is_string($handle) && ! is_numeric($handle)) {
            return null;
        }

        $normalized = mb_strtolower(ltrim(trim((string) $handle), '@'));

        return $normalized === '' ? null : $normalized;
    }

    public static function isWeak(string $handle, Platform|string|null $platform = null): bool
    {
        $normalized = self::normalize($handle);

        if ($normalized === null) {
            return true;
        }

        if (mb_strlen($normalized) < 3) {
            return true;
        }

        if (in_array($normalized, self::WEAK_TOKENS, true)) {
            return true;
        }

        $platformValue = $platform instanceof Platform ? $platform->value : (is_string($platform) ? strtolower($platform) : null);

        if ($platformValue === Platform::Facebook->value && preg_match('/^\d{6,}$/', $normalized) === 1) {
            return true;
        }

        return false;
    }
}
