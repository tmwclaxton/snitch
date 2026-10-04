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

        $generate = $events->first(fn ($expression, $command) => str_contains($command, 'snitch:generate-daily-briefs'));

        $this->assertSame('25 6 * * *', $generate);
        $this->assertFalse(
            $events->keys()->contains(fn (string $command): bool => str_contains($command, 'snitch:daily-refresh')),
            'Daily brief scrape is folded into snitch:sync-accounts; do not schedule a second scrape',
        );
    }
}
