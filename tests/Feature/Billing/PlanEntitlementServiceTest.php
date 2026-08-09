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
