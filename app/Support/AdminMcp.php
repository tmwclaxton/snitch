<?php

namespace App\Support;

/**
 * Admin surfaces are web-only. MCP must never expose admin overview,
 * COGS/profit aggregates, or other allowlisted-operator tooling.
 */
final class AdminMcp
{
    public static function isBlockedTool(?string $tool): bool
    {
        if ($tool === null) {
            return false;
        }

        $name = strtolower(trim($tool));

        if ($name === '') {
            return false;
        }

        return $name === 'admin'
            || str_starts_with($name, 'admin_')
            || str_starts_with($name, 'admin.');
    }
}
