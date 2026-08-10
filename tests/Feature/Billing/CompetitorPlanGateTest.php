<?php

namespace Tests\Feature\Billing;

use App\Jobs\SyncTrackedAccountJob;
use App\Models\BrandProfile;
use App\Models\TrackedAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\WithPlatformBilling;
use Tests\TestCase;

class CompetitorPlanGateTest extends TestCase
{
    use RefreshDatabase;
    use WithPlatformBilling;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
    }

    public function test_user_can_create_competitors_without_seat_caps(): void
    {
        $user = User::factory()->create();
        BrandProfile::factory()->for($user)->create();
        $this->enablePlatformBilling($user);
        TrackedAccount::factory()->count(5)->for($user)->create();

        $this->actingAs($user)
            ->post(route('competitors.store'), [
                'platform' => 'instagram',
                'handle' => 'one_more',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('tracked_accounts', [
            'user_id' => $user->id,
            'handle' => 'one_more',
        ]);

        Queue::assertPushed(SyncTrackedAccountJob::class);
    }
}
