<?php

namespace Tests\Feature;

use App\Enums\Platform;
use App\Enums\TrackedAccountKind;
use App\Models\BrandProfile;
use App\Models\TrackedAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\WithPlatformBilling;
use Tests\TestCase;

class OwnAccountKindTest extends TestCase
{
    use RefreshDatabase;
    use WithPlatformBilling;

    public function test_saving_own_account_forces_kind_own(): void
    {
        $user = User::factory()->create();

        $account = TrackedAccount::factory()->for($user)->create([
            'platform' => Platform::Instagram,
            'handle' => 'brandme',
            'is_own_account' => true,
            'kind' => TrackedAccountKind::Competitor,
        ]);

        $this->assertTrue($account->fresh()->is_own_account);
        $this->assertSame(TrackedAccountKind::Own, $account->fresh()->kind);
    }

    public function test_mark_own_and_unmark_keep_kind_aligned(): void
    {
        $user = User::factory()->create();
        BrandProfile::factory()->for($user)->create();
        $this->enablePlatformBilling($user);

        $account = TrackedAccount::factory()->for($user)->create([
            'platform' => Platform::Instagram,
            'handle' => 'toggleme',
            'is_own_account' => false,
            'kind' => TrackedAccountKind::Competitor,
        ]);

        $this->actingAs($user)
            ->post(route('competitors.own', $account))
            ->assertRedirect();

        $account->refresh();
        $this->assertTrue($account->is_own_account);
        $this->assertSame(TrackedAccountKind::Own, $account->kind);

        $this->actingAs($user)
            ->delete(route('competitors.unown', $account))
            ->assertRedirect();

        $account->refresh();
        $this->assertFalse($account->is_own_account);
        $this->assertSame(TrackedAccountKind::Competitor, $account->kind);
    }

    public function test_competitors_scope_includes_own_kind(): void
    {
        $user = User::factory()->create();
        $own = TrackedAccount::factory()->for($user)->own()->create([
            'platform' => Platform::Instagram,
            'handle' => 'scopedown',
        ]);
        $rival = TrackedAccount::factory()->for($user)->create([
            'platform' => Platform::Instagram,
            'handle' => 'scopedrival',
            'kind' => TrackedAccountKind::Competitor,
        ]);
        TrackedAccount::factory()->for($user)->influencer()->create([
            'platform' => Platform::Instagram,
            'handle' => 'scopedinfluencer',
        ]);

        $ids = TrackedAccount::query()
            ->where('user_id', $user->id)
            ->competitors()
            ->pluck('id')
            ->all();

        $this->assertContains($own->id, $ids);
        $this->assertContains($rival->id, $ids);
        $this->assertCount(2, $ids);
    }
}
