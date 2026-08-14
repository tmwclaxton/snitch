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
                    ->where('on_trial', true)
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
        // Shared subscription stays lazy (not in the partial response). Controllers
        // may still run a paywall gate for product omission; those credit queries
        // are allowed. Cashier subscription lookups must not run via shared props.
        $response = $this->actingAs($user)
            ->withHeaders([
                'X-Inertia' => 'true',
                'X-Inertia-Version' => (string) app(Middleware::class)->version(request()),
                'X-Inertia-Partial-Component' => 'Dashboard',
                'X-Inertia-Partial-Data' => 'activity',
            ])
            ->get(route('dashboard'))
            ->assertOk();

        $payload = $response->json();
        $this->assertIsArray($payload);
        $this->assertArrayNotHasKey(
            'subscription',
            $payload['props'] ?? [],
            'Partial defer reload must not resolve the shared subscription prop.',
        );

        $subscriptionTableQueries = array_values(array_filter(
            $sharedQueries,
            fn (string $sql): bool => str_contains($sql, 'subscriptions'),
        ));

        $this->assertSame(
            [],
            $subscriptionTableQueries,
            'Partial defer reload should not query the subscriptions table from shared props.',
        );
    }
}
