<?php

namespace Tests\Feature\Billing;

use App\Models\BrandProfile;
use App\Models\TrackedAccount;
use App\Models\User;
use App\Services\Billing\PlanEntitlementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
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
    public function competitors_index_does_not_surface_seat_limit_warnings(): void
    {
        $user = User::factory()->create();
        BrandProfile::factory()->for($user)->create();
        TrackedAccount::factory()->count(3)->for($user)->create();

        $response = $this->actingAs($user)
            ->get(route('competitors.index'));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('competitors/Index')
            ->missing('accounts')
            ->where('competitorCap.competitor_limit', null)
            ->where('competitorCap.competitors_remaining', null)
            ->where('competitorCap.over_quota_competitors', 0)
            ->loadDeferredProps('default', fn (Assert $page) => $page
                ->has('accounts', 3)
            )
        );

        $content = $response->getContent() ?: '';

        $this->assertStringNotContainsString('competitor limit', strtolower($content));
        $this->assertStringNotContainsString('plan limit', strtolower($content));
        $this->assertStringNotContainsString('over your plan limit', strtolower($content));
    }
}
