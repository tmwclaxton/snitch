<?php

namespace Tests\Feature\Marketing;

use App\Enums\BillingVendor;
use App\Models\CreditLedgerEntry;
use App\Models\User;
use App\Services\Billing\UsageBillingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PricingPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'billing.platform_fee_pence' => 1900,
            'billing.subscription_bonus_pence' => 3000,
            'billing.global_averages_cache_seconds' => 60,
        ]);

        Cache::forget('billing.global_vendor_averages');
    }

    public function test_pricing_page_exposes_live_global_tool_averages(): void
    {
        $alice = User::factory()->withoutStarterCredit()->create();
        $bob = User::factory()->withoutStarterCredit()->create();

        // NanoGPT: 1.03p + 2.06p => avg 1.545 -> round half-up to 1.55p
        $this->seedCharge($alice, BillingVendor::NanoGpt, -1.03, 'nano-a');
        $this->seedCharge($bob, BillingVendor::NanoGpt, -2.06, 'nano-b');
        // Apify: two 4.00p charges => avg 4.00p
        $this->seedCharge($alice, BillingVendor::Apify, -4.0, 'apify-a');
        $this->seedCharge($bob, BillingVendor::Apify, -4.0, 'apify-b');

        $this->get(route('pricing'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('marketing/Pricing')
                ->where('platform.fee_pence', 1900)
                ->where('platform.bonus_pence', 3000)
                ->has('toolAverages', 5)
                ->where('toolAverages.0.vendor', 'apify')
                ->where('toolAverages.0.avg_pence', fn (mixed $value): bool => (float) $value === 4.0)
                ->where('toolAverages.0.entries', 2)
                ->where('toolAverages.1.vendor', 'nanogpt')
                ->where('toolAverages.1.avg_pence', fn (mixed $value): bool => (float) $value === 1.55)
                ->where('toolAverages.1.entries', 2)
                ->where('toolAverages.2.vendor', 'firecrawl')
                ->where('toolAverages.2.avg_pence', fn (mixed $value): bool => (float) $value === 0.0)
                ->where('toolAverages.2.entries', 0)
                ->where('toolAverages.3.vendor', 'tikhub')
                ->where('toolAverages.4.vendor', 'snitch')
            );
    }

    public function test_global_vendor_averages_span_all_users(): void
    {
        $billing = app(UsageBillingService::class);

        $alice = User::factory()->withoutStarterCredit()->create();
        $bob = User::factory()->withoutStarterCredit()->create();

        $this->seedCharge($alice, BillingVendor::NanoGpt, -1.0, 'nano-alice');
        $this->seedCharge($bob, BillingVendor::NanoGpt, -3.0, 'nano-bob');

        Cache::forget('billing.global_vendor_averages');

        $averages = collect($billing->globalVendorAverages())->keyBy('vendor');

        $this->assertSame(2.0, $averages['nanogpt']['avg_pence']);
        $this->assertSame(2, $averages['nanogpt']['entries']);
        $this->assertSame(4.0, $averages['nanogpt']['spend_pence']);
    }

    public function test_pricing_page_works_with_empty_ledger(): void
    {
        $this->get(route('pricing'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('marketing/Pricing')
                ->has('toolAverages', 5)
                ->where('toolAverages.0.avg_pence', fn (mixed $value): bool => (float) $value === 0.0)
                ->where('toolAverages.0.entries', 0)
            );
    }

    private function seedCharge(User $user, BillingVendor $vendor, float $amountPence, string $key): void
    {
        CreditLedgerEntry::query()->create([
            'user_id' => $user->id,
            'action' => 'analyze.post',
            'vendor' => $vendor,
            'cogs_usd' => 0.01,
            'multiplier' => 1.3,
            'amount_pence' => $amountPence,
            'balance_after_pence' => 1000,
            'meta' => [],
            'idempotency_key' => 'pricing-avg:'.$key,
        ]);
    }
}
