<?php

namespace App\Http\Controllers\Marketing;

use App\Http\Controllers\Controller;
use App\Services\Billing\UsageBillingService;
use Inertia\Inertia;
use Inertia\Response;

class PricingController extends Controller
{
    public function __construct(
        private UsageBillingService $usage,
    ) {}

    public function __invoke(): Response
    {
        return Inertia::render('marketing/Pricing', [
            'toolAverages' => $this->usage->globalVendorAverages(),
            'platform' => [
                'fee_pence' => (int) config('billing.platform_fee_pence', 1900),
                'bonus_pence' => (int) config('billing.subscription_bonus_pence', 3000),
            ],
        ]);
    }
}
