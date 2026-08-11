<?php

namespace Tests\Feature;

use App\Models\BrandProfile;
use App\Models\User;
use App\Services\Billing\UsageBillingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Middleware;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SharedInertiaPropsTest extends TestCase
{
    use RefreshDatabase;

    public function test_shared_subscription_is_the_slim_payload_without_usage_aggregates(): void
    {
        $user = User::factory()->create();
        BrandProfile::factory()->for($user)->create();
        app(UsageBillingService::class)->creditClaimBonus($user);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('subscription', fn (Assert $subscription) => $subscription
                    ->where('subscribed', false)
                    ->where('can_run_billable', true)
                    ->where('balance_pence', 500)
                    ->where('min_run_balance_pence', 20)
                    ->where('paywall.blocked', false)
                    ->has('plan')
                    ->has('plan_name')
                    ->has('competitors_used')
                    ->has('influencers_used')
                    ->missing('usage')
                    ->missing('recent')
                    ->missing('vendors')
                    ->missing('all_time_spend_pence')
                    ->etc()
                )
            );
    }

    public function test_defer_partial_reload_skips_shared_subscription_query(): void
    {
        $user = User::factory()->create();
        BrandProfile::factory()->for($user)->create();
        app(UsageBillingService::class)->creditClaimBonus($user);

        $sharedQueries = [];
        DB::listen(function ($query) use (&$sharedQueries): void {
            $sharedQueries[] = $query->sql;
        });

        // Simulate an Inertia defer partial reload for the "activity" group only.
        // The slim shared subscription prop must stay lazy (not resolved). Controllers
        // may still run a lightweight canAccessProduct check when omitting product data.
        $response = $this->actingAs($user)
            ->withHeaders([
                'X-Inertia' => 'true',
                'X-Inertia-Version' => (string) app(Middleware::class)->version(request()),
                'X-Inertia-Partial-Component' => 'Dashboard',
                'X-Inertia-Partial-Data' => 'activity',
            ])
            ->get(route('dashboard'))
            ->assertOk();

        $props = $response->json('props') ?? [];
        $this->assertArrayNotHasKey('subscription', $props);
        $this->assertArrayHasKey('activity', $props);

        // sharedSummary counts competitors/influencers by kind when not blocked.
        // Those scoped counts must not run on a defer partial that omitted subscription.
        $sharedSummaryUsage = array_filter($sharedQueries, function (string $sql): bool {
            return str_contains($sql, 'tracked_accounts')
                && str_contains($sql, 'kind');
        });

        $this->assertSame(
            [],
            array_values($sharedSummaryUsage),
            'Partial defer reload should not resolve shared subscription usage counts.',
        );
    }
}
