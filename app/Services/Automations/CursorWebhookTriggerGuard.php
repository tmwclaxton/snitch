<?php

namespace App\Services\Automations;

use Illuminate\Support\Facades\Cache;

/**
 * Prevents double-firing the same Cursor automation webhook within a short window.
 */
class CursorWebhookTriggerGuard
{
    public function seconds(): int
    {
        return max(30, (int) config('services.cursor_automations.trigger_guard_seconds', 180));
    }

    public function key(string $type, int $userId, ?string $extra = null): string
    {
        $suffix = $extra !== null && $extra !== '' ? ':'.$extra : '';

        return 'cursor-automation-trigger:'.$type.':'.$userId.$suffix;
    }

    public function recentlyTriggered(string $type, int $userId, ?string $extra = null): bool
    {
        return Cache::has($this->key($type, $userId, $extra));
    }

    public function mark(string $type, int $userId, ?string $extra = null): void
    {
        Cache::put($this->key($type, $userId, $extra), now()->toIso8601String(), $this->seconds());
    }
}
