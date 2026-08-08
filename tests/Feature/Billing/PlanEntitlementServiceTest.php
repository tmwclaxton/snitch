<?php

namespace Tests\Feature\Billing;

use App\Models\TrackedAccount;
use App\Models\User;
use App\Services\Billing\PlanEntitlementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
            'subscriptions.plans.basic.stripe_price' => 'price_basic_test',
            'subscriptions.plans.pro.stripe_price' => 'price_pro_test',
            'subscriptions.plans.basic.stripe_price_yearly' => 'price_basic_yearly_test',
            'subscriptions.plans.pro.stripe_price_yearly' => 'price_pro_yearly_test',
        ]);

        $this->entitlements = app(PlanEntitlementService::class);
    }

    public function test_trial_user_gets_basic_entitlements(): void
    {
        $user = User::factory()->onTrial()->create();

        $this->assertTrue($this->entitlements->onTrial($user));
        $this->assertSame('basic', $this->entitlements->plan($user));
        $this->assertSame(10, $this->entitlements->competitorLimit($user));
    }

    public function test_expired_trial_without_subscription_is_free(): void
    {
        $user = User::factory()->freePlan()->create();

        $this->assertFalse($this->entitlements->onTrial($user));
        $this->assertSame('free', $this->entitlements->plan($user));
        $this->assertSame(3, $this->entitlements->competitorLimit($user));
    }

    public function test_active_basic_subscription_wins_over_trial(): void
    {
        $user = User::factory()->onTrial()->create();
        $this->createSubscription($user, 'price_basic_test');

        $this->assertSame('basic', $this->entitlements->plan($user));
        $this->assertFalse($this->entitlements->onTrial($user));
        $this->assertSame(10, $this->entitlements->competitorLimit($user));
    }

    public function test_active_pro_subscription_allows_fifty_competitors(): void
    {
        $user = User::factory()->freePlan()->create();
        $this->createSubscription($user, 'price_pro_test');

        $this->assertSame('pro', $this->entitlements->plan($user));
        $this->assertSame(50, $this->entitlements->competitorLimit($user));
    }

    public function test_yearly_basic_subscription_maps_to_basic_entitlements(): void
    {
        $user = User::factory()->freePlan()->create();
        $this->createSubscription($user, 'price_basic_yearly_test');

        $this->assertSame('basic', $this->entitlements->plan($user));
        $this->assertSame(10, $this->entitlements->competitorLimit($user));
        $this->assertSame('year', $this->entitlements->summary($user)['billing_interval']);
    }

    public function test_yearly_pro_subscription_maps_to_pro_entitlements(): void
    {
        $user = User::factory()->freePlan()->create();
        $this->createSubscription($user, 'price_pro_yearly_test');

        $this->assertSame('pro', $this->entitlements->plan($user));
        $this->assertSame(50, $this->entitlements->competitorLimit($user));
        $this->assertSame('year', $this->entitlements->summary($user)['billing_interval']);
    }

    public function test_competitors_remaining_respects_usage(): void
    {
        $user = User::factory()->freePlan()->create();
        TrackedAccount::factory()->count(2)->for($user)->create();

        $this->assertSame(2, $this->entitlements->competitorsUsed($user));
        $this->assertSame(1, $this->entitlements->competitorsRemaining($user));
        $this->assertTrue($this->entitlements->canAddCompetitors($user, 1));
        $this->assertFalse($this->entitlements->canAddCompetitors($user, 2));
    }

    public function test_ensure_trial_started_sets_trial_once(): void
    {
        $user = User::factory()->create(['trial_ends_at' => null]);

        $this->entitlements->ensureTrialStarted($user);
        $user->refresh();

        $this->assertNotNull($user->trial_ends_at);
        $this->assertTrue($user->trial_ends_at->isFuture());

        $original = $user->trial_ends_at->toIso8601String();
        $this->entitlements->ensureTrialStarted($user);
        $user->refresh();

        $this->assertSame($original, $user->trial_ends_at->toIso8601String());
    }

    public function test_unknown_subscription_price_does_not_masquerade_as_paid_plan(): void
    {
        $user = User::factory()->freePlan()->create();
        $this->createSubscription($user, 'price_unknown_other');

        $this->assertSame('free', $this->entitlements->plan($user));
        $this->assertSame(3, $this->entitlements->competitorLimit($user));
    }

    public function test_summary_marks_trial_and_upgrade_flags(): void
    {
        $user = User::factory()->onTrial()->create();
        TrackedAccount::factory()->count(3)->for($user)->create();

        $summary = $this->entitlements->summary($user);

        $this->assertSame('basic', $summary['plan']);
        $this->assertTrue($summary['on_trial']);
        $this->assertFalse($summary['subscribed']);
        $this->assertTrue($summary['can_upgrade']);
        $this->assertSame(3, $summary['competitors_used']);
        $this->assertSame(7, $summary['competitors_remaining']);
    }

    public function test_pro_summary_cannot_upgrade_further(): void
    {
        $user = User::factory()->freePlan()->create();
        $this->createSubscription($user, 'price_pro_test');

        $summary = $this->entitlements->summary($user);

        $this->assertSame('pro', $summary['plan']);
        $this->assertTrue($summary['subscribed']);
        $this->assertSame('month', $summary['billing_interval']);
        $this->assertFalse($summary['can_upgrade']);
        $this->assertFalse($summary['on_trial']);
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
