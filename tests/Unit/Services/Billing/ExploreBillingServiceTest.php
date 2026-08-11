<?php

namespace Tests\Unit\Services\Billing;

use App\Services\Billing\ExploreBillingService;
use App\Services\Billing\UsageBillingService;
use Tests\TestCase;

class ExploreBillingServiceTest extends TestCase
{
    public function test_search_charge_pence_is_zero_for_empty_results(): void
    {
        config([
            'billing.actions.explore.search' => [
                'vendor' => 'snitch',
                'max_pence' => 0.5,
                'results_for_max_pence' => 24,
            ],
        ]);

        $service = new ExploreBillingService(app(UsageBillingService::class));

        $this->assertSame(0.0, $service->searchChargePence(0));
    }

    public function test_search_charge_pence_scales_linearly_up_to_max(): void
    {
        config([
            'billing.actions.explore.search' => [
                'vendor' => 'snitch',
                'max_pence' => 0.5,
                'results_for_max_pence' => 24,
            ],
        ]);

        $service = new ExploreBillingService(app(UsageBillingService::class));

        $this->assertSame(0.02, $service->searchChargePence(1));
        $this->assertSame(0.25, $service->searchChargePence(12));
        $this->assertSame(0.5, $service->searchChargePence(24));
        $this->assertSame(0.5, $service->searchChargePence(100));
    }
}
