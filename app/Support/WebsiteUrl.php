<?php

namespace App\Support;

class WebsiteUrl
{
    public static function normalize(?string $website): ?string
    {
        if ($website === null) {
            return null;
        }

        $trimmed = trim($website);

        if ($trimmed === '') {
            return null;
        }

        if (! preg_match('#^https?://#i', $trimmed)) {
            $trimmed = 'https://'.$trimmed;
        }

        return $trimmed;
    }

    public static function hasValidHost(?string $website): bool
    {
        if ($website === null || trim($website) === '') {
            return false;
        }

        $host = parse_url($website, PHP_URL_HOST);

        if (! is_string($host) || $host === '') {
            return false;
        }

        return str_contains($host, '.');
    }
}
