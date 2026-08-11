<?php

namespace Tests\Feature\Billing;

use App\Models\CreditBalance;
use App\Models\TrackedAccount;
use App\Models\User;
use App\Services\Billing\PlanEntitlementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Cashier\Subscription;
use Tests\TestCase;

class PlanEntitlementServiceTest extends TestCase
{
    use RefreshDatabase;

    private PlanEntitlementService $entitlements;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'billing.platform_stripe_price' => 'price_platform_test',
        ]);

        $this->entitlements = app(PlanEntitlementService::class);
    }

    public function test_unlimited_competitors_without_seat_caps(): void
    {
        $user = User::factory()->create();
        TrackedAccount::factory()->count(5)->for($user)->create();

        $this->assertTrue($this->entitlements->canAddCompetitors($user, 100));
        $this->assertTrue($this->entitlements->canAddInfluencers($user, 100));
        $this->assertCount(5, $this->entitlements->inQuotaTrackedAccountIds($user));
    }

    public function test_summary_reflects_platform_subscription(): void
    {
        $user = User::factory()->create();
        $this->assertFalse($this->entitlements->summary($user)['subscribed']);

        $this->createSubscription($user, 'price_platform_test');
        $user->refresh();

        $summary = $this->entitlements->summary($user);

        $this->assertTrue($summary['subscribed']);
        $this->assertSame('platform', $summary['plan']);
        $this->assertNull($summary['competitor_limit']);
    }

    public function test_shared_summary_returns_flat_payload_without_usage_aggregates(): void
    {
        $user = User::factory()->create();
        CreditBalance::query()->create([
            'user_id' => $user->id,
            'balance_pence' => 5000,
        ]);
        TrackedAccount::factory()->count(2)->competitor()->for($user)->create();
        TrackedAccount::factory()->count(1)->influencer()->for($user)->create();

        $shared = $this->entitlements->sharedSummary($user);

        $this->assertSame('none', $shared['plan']);
        $this->assertFalse($shared['subscribed']);
        $this->assertTrue($shared['can_upgrade']);
        $this->assertSame(2, $shared['competitors_used']);
        $this->assertSame(1, $shared['influencers_used']);
        $this->assertSame(5000.0, $shared['balance_pence']);
        $this->assertTrue($shared['can_run_billable']);
        $this->assertArrayNotHasKey('usage', $shared);
        $this->assertArrayNotHasKey('recent', $shared);
        $this->assertArrayNotHasKey('vendors', $shared);
        $this->assertArrayNotHasKey('all_time_spend_pence', $shared);
    }

    public function test_shared_summary_can_run_billable_gates_on_min_run_balance(): void
    {
        $user = User::factory()->create();

        $balance = CreditBalance::query()->create([
            'user_id' => $user->id,
            'balance_pence' => 20,
        ]);

        $this->assertFalse($this->entitlements->sharedSummary($user)['can_run_billable']);

        $balance->update(['balance_pence' => 21]);

        $freshEntitlements = app(PlanEntitlementService::class);
        $this->assertTrue($freshEntitlements->sharedSummary($user)['can_run_billable']);
    }

    public function test_shared_summary_is_memoized_per_user_within_request(): void
    {
        $user = User::factory()->create();

        $first = $this->entitlements->sharedSummary($user);

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        $second = $this->entitlements->sharedSummary($user);

        $this->assertSame($first, $second);
        $this->assertSame(0, $queryCount, 'sharedSummary should reuse the cached payload for the same user.');
    }

    public function test_shared_summary_avoids_the_heavy_ledger_queries_from_summary(): void
    {
        $user = User::factory()->create();
        CreditBalance::query()->create([
            'user_id' => $user->id,
            'balance_pence' => 100,
        ]);

        DB::connection()->enableQueryLog();
        app(PlanEntitlementService::class)->sharedSummary($user);
        $sharedQueries = DB::connection()->getQueryLog();
        DB::connection()->flushQueryLog();

        app(PlanEntitlementService::class)->summary($user);
        $fullQueries = DB::connection()->getQueryLog();
        DB::connection()->disableQueryLog();

        $ledgerFromShared = array_filter(
            $sharedQueries,
            fn (array $q): bool => str_contains($q['query'], 'credit_ledger_entries'),
        );
        $ledgerFromSummary = array_filter(
            $fullQueries,
            fn (array $q): bool => str_contains($q['query'], 'credit_ledger_entries'),
        );

        $this->assertSame(
            [],
            array_values($ledgerFromShared),
            'sharedSummary must not query credit_ledger_entries.',
        );
        $this->assertNotEmpty(
            $ledgerFromSummary,
            'summary() should still query credit_ledger_entries for the spend rollup.',
        );
    }

    private function createSubscription(User $user, string $priceId): Subscription
    {
        return $user->subscriptions()->create([
            'type' => 'default',
            'stripe_id' => 'sub_'.uniqid(),
            'stripe_status' => 'active',
            'stripe_price' => $priceId,
            'quantity' => 1,
        ]);
    }
}
