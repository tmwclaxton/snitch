<?php

namespace Tests\Feature\DailyBrief;

use App\Enums\Platform;
use App\Jobs\GenerateDailyBriefJob;
use App\Models\TrackedAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class GenerateDailyBriefsAudienceTest extends TestCase
{
    use RefreshDatabase;

    public function test_queues_briefs_for_every_user_with_trackers_not_only_opt_in(): void
    {
        Queue::fake();

        $optedIn = User::factory()->create(['daily_brief_enabled' => true]);
        $optedOut = User::factory()->create(['daily_brief_enabled' => false]);
        $noTrackers = User::factory()->create(['daily_brief_enabled' => true]);

        TrackedAccount::factory()->for($optedIn)->create(['platform' => Platform::Instagram]);
        TrackedAccount::factory()->for($optedOut)->create(['platform' => Platform::Instagram]);

        $this->artisan('snitch:generate-daily-briefs')->assertSuccessful();

        Queue::assertPushed(GenerateDailyBriefJob::class, 2);
        Queue::assertPushed(
            GenerateDailyBriefJob::class,
            fn (GenerateDailyBriefJob $job) => $job->userId === $optedIn->id,
        );
        Queue::assertPushed(
            GenerateDailyBriefJob::class,
            fn (GenerateDailyBriefJob $job) => $job->userId === $optedOut->id,
        );
        Queue::assertNotPushed(
            GenerateDailyBriefJob::class,
            fn (GenerateDailyBriefJob $job) => $job->userId === $noTrackers->id,
        );
    }
}
