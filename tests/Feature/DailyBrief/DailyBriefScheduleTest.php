<?php

namespace Tests\Feature\DailyBrief;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

class DailyBriefScheduleTest extends TestCase
{
    public function test_daily_refresh_and_generation_are_scheduled(): void
    {
        $events = collect(app(Schedule::class)->events())
            ->map(fn (Event $event): string => $event->command ?? $event->description ?? '');

        $this->assertTrue(
            $events->contains(fn (string $command): bool => str_contains($command, 'snitch:daily-refresh')),
            'Daily brief must schedule snitch:daily-refresh',
        );
        $this->assertTrue(
            $events->contains(fn (string $command): bool => str_contains($command, 'snitch:generate-daily-briefs')),
            'Daily brief must schedule snitch:generate-daily-briefs',
        );
    }
}
