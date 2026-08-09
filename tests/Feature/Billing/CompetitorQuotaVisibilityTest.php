<?php

namespace Tests\Feature\Billing;

use App\Models\BrandProfile;
use App\Models\TrackedAccount;
use App\Models\User;
use App\Services\Billing\PlanEntitlementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CompetitorQuotaVisibilityTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function all_tracked_accounts_remain_in_quota_without_seat_caps(): void
    {
        $user = User::factory()->create();
        $accounts = TrackedAccount::factory()->count(5)->for($user)->create();
        $ids = app(PlanEntitlementService::class)->inQuotaTrackedAccountIds($user);

        $this->assertSame(
            $accounts->sortBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all(),
            $ids,
        );
        $this->assertSame(0, app(PlanEntitlementService::class)->summary($user)['over_quota_competitors']);
    }

    #[Test]
    public function competitors_index_lists_accounts(): void
    {
        $user = User::factory()->create();
        BrandProfile::factory()->for($user)->create();
        TrackedAccount::factory()->count(2)->for($user)->create();

        $this->actingAs($user)
            ->get(route('competitors.index'))
            ->assertOk();
    }
}
