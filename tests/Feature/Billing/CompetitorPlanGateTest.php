<?php

namespace Tests\Feature\Billing;

use App\Jobs\SyncTrackedAccountJob;
use App\Models\BrandProfile;
use App\Models\TrackedAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class CompetitorPlanGateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'subscriptions.plans.basic.stripe_price' => 'price_basic_test',
            'subscriptions.plans.pro.stripe_price' => 'price_pro_test',
        ]);

        Queue::fake();
    }

    public function test_free_user_cannot_create_fourth_competitor(): void
    {
        $user = User::factory()->freePlan()->create();
        BrandProfile::factory()->for($user)->create();
        TrackedAccount::factory()->count(3)->for($user)->create();

        $this->actingAs($user)
            ->post(route('competitors.store'), [
                'platform' => 'instagram',
                'handle' => 'one_more',
            ])
            ->assertRedirect(route('competitors.index'));

        $this->assertDatabaseMissing('tracked_accounts', [
            'user_id' => $user->id,
            'handle' => 'one_more',
        ]);
    }

    public function test_free_user_can_create_up_to_three_competitors(): void
    {
        $user = User::factory()->freePlan()->create();
        BrandProfile::factory()->for($user)->create();
        TrackedAccount::factory()->count(2)->for($user)->create();

        $this->actingAs($user)
            ->post(route('competitors.store'), [
                'platform' => 'instagram',
                'handle' => 'third_rival',
            ])
            ->assertRedirect(route('competitors.index'));

        $this->assertDatabaseHas('tracked_accounts', [
            'user_id' => $user->id,
            'handle' => 'third_rival',
        ]);
        Queue::assertPushed(SyncTrackedAccountJob::class);
    }

    public function test_trial_user_can_create_up_to_ten_competitors(): void
    {
        $user = User::factory()->onTrial()->create();
        BrandProfile::factory()->for($user)->create();
        TrackedAccount::factory()->count(9)->for($user)->create();

        $this->actingAs($user)
            ->post(route('competitors.store'), [
                'platform' => 'tiktok',
                'handle' => 'tenth_rival',
            ])
            ->assertRedirect(route('competitors.index'));

        $this->assertDatabaseHas('tracked_accounts', [
            'user_id' => $user->id,
            'handle' => 'tenth_rival',
        ]);
    }

    public function test_trial_user_cannot_create_eleventh_competitor(): void
    {
        $user = User::factory()->onTrial()->create();
        BrandProfile::factory()->for($user)->create();
        TrackedAccount::factory()->count(10)->for($user)->create();

        $this->actingAs($user)
            ->post(route('competitors.store'), [
                'platform' => 'youtube',
                'handle' => 'eleventh',
            ])
            ->assertRedirect(route('competitors.index'));

        $this->assertDatabaseMissing('tracked_accounts', [
            'user_id' => $user->id,
            'handle' => 'eleventh',
        ]);
    }

    public function test_pro_user_can_create_up_to_fifty_competitors(): void
    {
        $user = User::factory()->freePlan()->create();
        BrandProfile::factory()->for($user)->create();
        $user->subscriptions()->create([
            'type' => 'default',
            'stripe_id' => 'sub_pro_gate',
            'stripe_status' => 'active',
            'stripe_price' => 'price_pro_test',
            'quantity' => 1,
        ]);
        TrackedAccount::factory()->count(49)->for($user)->create();

        $this->actingAs($user)
            ->post(route('competitors.store'), [
                'platform' => 'facebook',
                'handle' => 'fiftieth',
            ])
            ->assertRedirect(route('competitors.index'));

        $this->assertDatabaseHas('tracked_accounts', [
            'user_id' => $user->id,
            'handle' => 'fiftieth',
        ]);
    }

    public function test_confirm_suggestions_respects_net_new_limit(): void
    {
        $user = User::factory()->freePlan()->create();
        BrandProfile::factory()->for($user)->create();
        TrackedAccount::factory()->count(2)->for($user)->create();

        $this->actingAs($user)
            ->post(route('competitors.confirm-suggestions'), [
                'suggestions' => [
                    [
                        'platform' => 'instagram',
                        'handle' => 'ok_one',
                        'display_name' => 'Ok One',
                    ],
                    [
                        'platform' => 'instagram',
                        'handle' => 'too_many',
                        'display_name' => 'Too Many',
                    ],
                ],
            ])
            ->assertRedirect(route('competitors.index'));

        $this->assertDatabaseMissing('tracked_accounts', [
            'user_id' => $user->id,
            'handle' => 'ok_one',
        ]);
        $this->assertDatabaseMissing('tracked_accounts', [
            'user_id' => $user->id,
            'handle' => 'too_many',
        ]);
    }

    public function test_updating_existing_competitor_does_not_consume_slot(): void
    {
        $user = User::factory()->freePlan()->create();
        BrandProfile::factory()->for($user)->create();
        TrackedAccount::factory()->for($user)->create([
            'platform' => 'instagram',
            'handle' => 'already_here',
            'display_name' => 'Old Name',
        ]);
        TrackedAccount::factory()->count(2)->for($user)->create();

        $this->actingAs($user)
            ->post(route('competitors.store'), [
                'platform' => 'instagram',
                'handle' => 'already_here',
                'display_name' => 'Updated Name',
            ])
            ->assertRedirect(route('competitors.index'));

        $this->assertSame(3, $user->trackedAccounts()->count());
        $this->assertDatabaseHas('tracked_accounts', [
            'user_id' => $user->id,
            'handle' => 'already_here',
            'display_name' => 'Updated Name',
        ]);
    }
}
