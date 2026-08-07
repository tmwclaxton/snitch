<?php

namespace Tests\Feature;

use App\Jobs\SyncTrackedAccountJob;
use App\Models\BrandProfile;
use App\Models\TrackedAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CompetitorsTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_list_and_create_competitors(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        BrandProfile::factory()->for($user)->create();

        $this->actingAs($user)
            ->get(route('competitors.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('competitors/Index'));

        $this->actingAs($user)
            ->post(route('competitors.store'), [
                'platform' => 'instagram',
                'handle' => '@rivalbakery',
            ])
            ->assertRedirect(route('competitors.index'));

        $this->assertDatabaseHas('tracked_accounts', [
            'user_id' => $user->id,
            'handle' => 'rivalbakery',
            'platform' => 'instagram',
        ]);

        Queue::assertPushed(SyncTrackedAccountJob::class);
    }

    public function test_owner_can_delete_and_sync(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        BrandProfile::factory()->for($user)->create();
        $account = TrackedAccount::factory()->for($user)->create();

        $this->actingAs($user)
            ->post(route('competitors.sync', $account))
            ->assertRedirect();

        Queue::assertPushed(SyncTrackedAccountJob::class);

        $this->actingAs($user)
            ->delete(route('competitors.destroy', $account))
            ->assertRedirect(route('competitors.index'));

        $this->assertDatabaseMissing('tracked_accounts', ['id' => $account->id]);
    }

    public function test_other_user_cannot_delete_competitor(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        BrandProfile::factory()->for($other)->create();
        $account = TrackedAccount::factory()->for($owner)->create();

        $this->actingAs($other)
            ->delete(route('competitors.destroy', $account))
            ->assertForbidden();
    }
}
