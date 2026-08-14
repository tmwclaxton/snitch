<?php

namespace App\Support;

final class GoogleAnalytics
{
    public static function measurementId(): ?string
    {
        $id = config('services.google.analytics_id');

        if (! is_string($id) || $id === '') {
            return null;
        }

        return $id;
    }

    public static function enabled(): bool
    {
        if (self::measurementId() === null) {
            return false;
        }

        return (bool) config('services.google.analytics_enabled');
    }
}
