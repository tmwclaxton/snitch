<?php

namespace Tests\Feature;

use App\Enums\Platform;
use App\Jobs\SyncTrackedAccountJob;
use App\Models\BrandProfile;
use App\Models\TrackedAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\WithPlatformBilling;
use Tests\TestCase;

class CompetitorsOwnAccountTest extends TestCase
{
    use RefreshDatabase;
    use WithPlatformBilling;

    public function test_owner_can_add_own_instagram_account_and_toggle_flag(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        BrandProfile::factory()->for($user)->create();
        $this->enablePlatformBilling($user);

        $this->actingAs($user)
            ->post(route('competitors.store'), [
                'handle' => '@mybrand',
                'is_own_account' => true,
            ])
            ->assertRedirect(route('competitors.index'));

        $this->assertDatabaseHas('tracked_accounts', [
            'user_id' => $user->id,
            'handle' => 'mybrand',
            'platform' => Platform::Instagram->value,
            'is_own_account' => true,
        ]);

        Queue::assertPushed(SyncTrackedAccountJob::class);

        $account = TrackedAccount::query()->where('handle', 'mybrand')->firstOrFail();

        $this->actingAs($user)
            ->delete(route('competitors.unown', $account))
            ->assertRedirect();

        $this->assertFalse($account->fresh()->is_own_account);

        $this->actingAs($user)
            ->post(route('competitors.own', $account))
            ->assertRedirect();

        $this->assertTrue($account->fresh()->is_own_account);
    }

    public function test_marking_own_clears_the_previous_own_account(): void
    {
        $user = User::factory()->create();
        BrandProfile::factory()->for($user)->create();
        $this->enablePlatformBilling($user);

        $first = TrackedAccount::factory()->for($user)->create([
            'platform' => Platform::Instagram,
            'handle' => 'firstbrand',
            'is_own_account' => true,
        ]);
        $second = TrackedAccount::factory()->for($user)->create([
            'platform' => Platform::Instagram,
            'handle' => 'secondbrand',
            'is_own_account' => false,
        ]);

        $this->actingAs($user)
            ->post(route('competitors.own', $second))
            ->assertRedirect();

        $this->assertFalse($first->fresh()->is_own_account);
        $this->assertTrue($second->fresh()->is_own_account);
    }
}
