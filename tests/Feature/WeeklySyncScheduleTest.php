<?php

namespace Tests\Feature;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

class WeeklySyncScheduleTest extends TestCase
{
    public function test_weekly_instagram_sync_is_scheduled(): void
    {
        $events = collect(app(Schedule::class)->events())
            ->map(fn (Event $event): string => $event->command ?? $event->description ?? '');

        $this->assertTrue(
            $events->contains(fn (string $command): bool => str_contains($command, 'snitch:sync-accounts')),
            'Weekly refresh must schedule snitch:sync-accounts',
        );
    }
}
