<?php

namespace Tests\Feature\DailyBrief;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

class DailyBriefScheduleTest extends TestCase
{
    public function test_generation_is_scheduled_after_the_morning_sync(): void
    {
        $events = collect(app(Schedule::class)->events())
            ->mapWithKeys(fn (Event $event): array => [
                (string) ($event->command ?? $event->description ?? '') => $event->expression,
            ]);

        $this->assertFalse(
            $events->keys()->contains(fn (string $command): bool => str_contains($command, 'snitch:generate-daily-briefs')),
            'Daily brief generation is no longer scheduled',
        );
        $this->assertFalse(
            $events->keys()->contains(fn (string $command): bool => str_contains($command, 'snitch:generate-weekly-briefs')),
        );
        $this->assertFalse(
            $events->keys()->contains(fn (string $command): bool => str_contains($command, 'snitch:kickoff-brief-automations')),
        );
        $this->assertFalse(
            $events->keys()->contains(fn (string $command): bool => str_contains($command, 'snitch:daily-refresh')),
            'Daily brief scrape is folded into snitch:sync-accounts; do not schedule a second scrape',
        );
    }
}
