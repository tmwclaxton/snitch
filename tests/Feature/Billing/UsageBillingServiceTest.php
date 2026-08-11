<?php

namespace Tests\Feature\Billing;

use App\Enums\BillingVendor;
use App\Exceptions\InsufficientCreditsException;
use App\Models\CreditLedgerEntry;
use App\Models\User;
use App\Services\Billing\UsageBillingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Cashier\Subscription;
use Tests\TestCase;

class UsageBillingServiceTest extends TestCase
{
    use RefreshDatabase;

    private UsageBillingService $billing;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'billing.platform_stripe_price' => 'price_platform_test',
            'billing.claim_bonus_pence' => 500,
            'billing.subscription_bonus_pence' => 3000,
            'billing.price_multiplier' => 1.3,
            'billing.usd_to_gbp' => 1.0,
            'billing.min_run_balance_pence' => 20,
        ]);

        $this->billing = app(UsageBillingService::class);
    }

    public function test_agent_starts_with_zero_credits(): void
    {
        $user = User::factory()->unclaimedAgent()->create();

        $this->assertSame(0.0, $this->billing->balancePence($user));
        $this->assertFalse($this->billing->hasPlatformSubscription($user));
    }

    public function test_claim_bonus_is_idempotent(): void
    {
        $user = User::factory()->withoutStarterCredit()->create();

        $this->billing->creditClaimBonus($user);
        $this->billing->creditClaimBonus($user);

        $this->assertSame(500.0, $this->billing->balancePence($user));
    }

    public function test_subscription_bonus_is_idempotent_per_invoice(): void
    {
        $user = User::factory()->withoutStarterCredit()->create();

        $this->billing->creditSubscriptionBonus($user, 'subscription_bonus:invoice:in_test_1');
        $this->billing->creditSubscriptionBonus($user, 'subscription_bonus:invoice:in_test_1');
        $this->billing->creditSubscriptionBonus($user, 'subscription_bonus:invoice:in_test_2');

        $this->assertSame(6000.0, $this->billing->balancePence($user));
    }

    public function test_charge_works_without_subscription_when_balance_above_floor(): void
    {
        $user = User::factory()->withoutStarterCredit()->create();
        $this->billing->creditClaimBonus($user);

        $entry = $this->billing->charge($user, 'analyze.post', BillingVendor::NanoGpt, 0.04);

        $this->assertLessThan(0, $entry->amount_pence);
        $this->assertSame(BillingVendor::NanoGpt, $entry->vendor);
        $this->assertSame(500 + $entry->amount_pence, $this->billing->balancePence($user));
    }

    public function test_charge_deducts_credits_when_subscribed(): void
    {
        $user = User::factory()->withoutStarterCredit()->create();
        $this->subscribe($user);
        $this->billing->creditFromTopUp($user, 1000, 'topup:test2');

        $entry = $this->billing->charge($user, 'analyze.post', BillingVendor::NanoGpt, 0.04);

        $this->assertLessThan(0, $entry->amount_pence);
        $this->assertSame(BillingVendor::NanoGpt, $entry->vendor);
        $this->assertSame(1000 + $entry->amount_pence, $this->billing->balancePence($user));
    }

    public function test_assert_can_run_rejects_balance_at_or_below_20p(): void
    {
        $user = User::factory()->withoutStarterCredit()->create();
        $this->subscribe($user);
        $this->billing->creditFromTopUp($user, 20, 'topup:floor');

        try {
            $this->billing->assertCanRun($user);
            $this->fail('Expected InsufficientCreditsException');
        } catch (InsufficientCreditsException $exception) {
            $this->assertSame(20.0, $exception->balancePence);
            $this->assertStringContainsString('more than 20p', $exception->getMessage());
        }
    }

    public function test_assert_can_run_allows_balance_above_20p(): void
    {
        $user = User::factory()->withoutStarterCredit()->create();
        $this->subscribe($user);
        $this->billing->creditFromTopUp($user, 21, 'topup:above-floor');

        $this->billing->assertCanRun($user);

        $this->assertSame(21.0, $this->billing->balancePence($user));
    }

    public function test_insufficient_credits_throws_when_balance_is_zero(): void
    {
        $user = User::factory()->withoutStarterCredit()->create();
        $this->subscribe($user);

        $this->expectException(InsufficientCreditsException::class);

        $this->billing->charge($user, 'analyze.post', BillingVendor::NanoGpt, 0.04);
    }

    public function test_usage_summary_groups_vendors(): void
    {
        $user = User::factory()->withoutStarterCredit()->create();
        $this->subscribe($user);
        $this->billing->creditFromTopUp($user, 5000, 'topup:sum');

        $this->billing->charge($user, 'sync.account', BillingVendor::Apify, 0.05);
        $this->billing->charge($user, 'analyze.post', BillingVendor::NanoGpt, 0.04);
        $this->billing->charge($user, 'brand.autofill', BillingVendor::Firecrawl, 0.01);
        $this->billing->charge($user, 'explore.search', BillingVendor::Snitch);

        $summary = $this->billing->summary($user);

        $this->assertGreaterThan(0, $summary['vendors']['apify']['spend_pence']);
        $this->assertGreaterThan(0, $summary['vendors']['nanogpt']['spend_pence']);
        $this->assertGreaterThan(0, $summary['vendors']['firecrawl']['spend_pence']);
        $this->assertSame(0.5, $summary['vendors']['snitch']['spend_pence']);
        $this->assertArrayHasKey('tikhub', $summary['vendors']);
        $this->assertSame(0.0, $summary['vendors']['tikhub']['spend_pence']);
        $this->assertArrayNotHasKey('markup', $summary);
    }

    public function test_daily_spend_series_stacks_vendor_charges(): void
    {
        $user = User::factory()->withoutStarterCredit()->create();
        $this->subscribe($user);
        $this->billing->creditFromTopUp($user, 5000, 'topup:series');

        $this->billing->charge($user, 'sync.account', BillingVendor::Apify, 0.05);
        $this->billing->charge($user, 'analyze.post', BillingVendor::NanoGpt, 0.04);
        $this->billing->charge($user, 'brand.autofill', BillingVendor::Firecrawl, 0.01);
        $this->billing->charge($user, 'sync.account', BillingVendor::TikHub, 0.002);
        $this->billing->charge($user, 'explore.search', BillingVendor::Snitch);
        $this->billing->charge($user, 'explore.view', BillingVendor::Snitch);

        $series = $this->billing->dailySpendSeries($user, 14);

        $this->assertSame('day', $series['grain']);
        $this->assertSame(14, $series['period_count']);
        $this->assertCount(14, $series['points']);

        $today = collect($series['points'])->firstWhere('date', now()->toDateString());
        $this->assertNotNull($today);
        $this->assertGreaterThan(0, $today['apify']);
        $this->assertGreaterThan(0, $today['nanogpt']);
        $this->assertGreaterThan(0, $today['firecrawl']);
        $this->assertGreaterThan(0, $today['tikhub']);
        $this->assertSame(0.6, $today['snitch']);
        $this->assertSame(
            $today['apify'] + $today['nanogpt'] + $today['firecrawl'] + $today['tikhub'] + $today['snitch'],
            $today['total'],
        );
    }

    public function test_spend_series_aggregates_by_week_and_month(): void
    {
        $user = User::factory()->withoutStarterCredit()->create();
        $this->subscribe($user);
        $this->billing->creditFromTopUp($user, 10_000, 'topup:grain');

        $thisWeekStart = now()->startOfWeek();
        $sameWeek = $thisWeekStart->copy()->addDays(min(2, max(0, now()->dayOfWeekIso - 1)));
        $priorWeekStart = $thisWeekStart->copy()->subWeek();
        $priorWeekDay = $priorWeekStart->copy()->addDays(1);
        $priorMonthDay = now()->startOfMonth()->subMonthNoOverflow()->addDays(3);

        $first = $this->billing->charge($user, 'sync.account', BillingVendor::Apify, 0.05);
        $first->forceFill(['created_at' => $thisWeekStart])->save();

        $second = $this->billing->charge($user, 'sync.account', BillingVendor::Apify, 0.05);
        $second->forceFill(['created_at' => $sameWeek])->save();

        $third = $this->billing->charge($user, 'sync.account', BillingVendor::Apify, 0.05);
        $third->forceFill(['created_at' => $priorWeekDay])->save();

        $fourth = $this->billing->charge($user, 'analyze.post', BillingVendor::NanoGpt, 0.04);
        $fourth->forceFill(['created_at' => $priorMonthDay])->save();

        $weekly = $this->billing->spendSeries($user, 'week', 12);

        $this->assertSame('week', $weekly['grain']);
        $this->assertSame(12, $weekly['period_count']);
        $this->assertCount(12, $weekly['points']);

        $thisWeek = collect($weekly['points'])->firstWhere('date', $thisWeekStart->toDateString());
        $lastWeek = collect($weekly['points'])->firstWhere('date', $priorWeekStart->toDateString());

        $this->assertNotNull($thisWeek);
        $this->assertNotNull($lastWeek);
        $this->assertSame(
            abs($first->amount_pence) + abs($second->amount_pence),
            $thisWeek['apify'],
        );
        $this->assertSame(abs($third->amount_pence), $lastWeek['apify']);

        $monthly = $this->billing->spendSeries($user, 'month', 12);

        $this->assertSame('month', $monthly['grain']);
        $this->assertSame(12, $monthly['period_count']);
        $this->assertCount(12, $monthly['points']);

        $thisMonthKey = now()->startOfMonth()->toDateString();
        $lastMonthKey = $priorMonthDay->copy()->startOfMonth()->toDateString();

        $thisMonth = collect($monthly['points'])->firstWhere('date', $thisMonthKey);
        $lastMonth = collect($monthly['points'])->firstWhere('date', $lastMonthKey);

        $this->assertNotNull($thisMonth);
        $this->assertNotNull($lastMonth);

        $expectedThisMonthApify = collect([$first, $second, $third])
            ->filter(fn ($entry) => $entry->fresh()->created_at?->format('Y-m') === now()->format('Y-m'))
            ->sum(fn ($entry) => abs($entry->amount_pence));

        $this->assertSame($expectedThisMonthApify, $thisMonth['apify']);
        $this->assertSame(abs($fourth->amount_pence), $lastMonth['nanogpt']);
    }

    public function test_missing_nanogpt_tokens_use_catalog_cogs_not_min_charge(): void
    {
        config([
            'billing.usd_to_gbp' => 0.79,
            'billing.price_multiplier' => 1.3,
            'billing.vendors.nanogpt.floors_usd.video_analysis' => 0.0005,
            'billing.actions.analyze.post.floor_usd' => 0.0005,
        ]);

        $cogs = $this->billing->estimateNanoGptChatUsd(
            null,
            null,
            'deepseek/deepseek-v4-flash',
            'video_analysis',
        );

        // 0.0005 * 0.79 * 1.3 * 100 = 0.05135p → round half-up to 0.05p (£0.0005)
        $this->assertSame(0.0005, $cogs);
        $this->assertSame(0.05, $this->billing->pricePenceFromCogs('analyze.post', BillingVendor::NanoGpt, $cogs));

        $user = User::factory()->withoutStarterCredit()->create();
        $this->billing->creditClaimBonus($user);
        $entry = $this->billing->charge($user, 'analyze.post', BillingVendor::NanoGpt, $cogs);

        $this->assertSame(-0.05, $entry->amount_pence);
        $this->assertSame(499.95, $this->billing->balancePence($user));
    }

    public function test_vendors_round_to_centipence_without_minimum(): void
    {
        config([
            'billing.usd_to_gbp' => 0.79,
            'billing.price_multiplier' => 1.3,
        ]);

        // 0.001 * 0.79 * 1.3 * 100 = 0.1027p → 0.10p (£0.0010), not ceil to 1p
        $this->assertSame(0.1, $this->billing->pricePenceFromCogs('tikhub.run', BillingVendor::TikHub, 0.001));

        // $0.01 Apify: 0.01 * 0.79 * 1.3 * 100 = 1.027p → 1.03p (£0.0103)
        $this->assertSame(1.03, $this->billing->pricePenceFromCogs('apify.run', BillingVendor::Apify, 0.01));

        $this->assertSame(0.0, $this->billing->pricePenceFromCogs('analyze.post', BillingVendor::NanoGpt, 0.0));
    }

    public function test_charge_skips_ledger_when_amount_rounds_to_zero(): void
    {
        $user = User::factory()->withoutStarterCredit()->create();
        $this->subscribe($user);
        $this->billing->creditFromTopUp($user, 1000, 'topup:zero-skip');

        $entry = $this->billing->charge(
            $user,
            'influencers.find',
            BillingVendor::Apify,
            0.0,
            ['run_id' => 'run_zero'],
            'apify:run_zero',
        );

        $this->assertNull($entry);
        $this->assertSame(0, CreditLedgerEntry::query()->where('user_id', $user->id)->where('amount_pence', '<=', 0)->count());
        $this->assertSame(1000.0, $this->billing->balancePence($user));
        $this->assertFalse(
            CreditLedgerEntry::query()->where('idempotency_key', 'apify:run_zero')->exists(),
        );
    }

    public function test_recent_and_charges_hide_zero_amount_rows(): void
    {
        $user = User::factory()->withoutStarterCredit()->create();
        $this->subscribe($user);
        $this->billing->creditFromTopUp($user, 1000, 'topup:hide-zero');
        $this->billing->charge($user, 'analyze.post', BillingVendor::NanoGpt, 0.04);

        CreditLedgerEntry::query()->create([
            'user_id' => $user->id,
            'action' => 'influencers.find',
            'vendor' => BillingVendor::Apify,
            'cogs_usd' => 0,
            'multiplier' => 1.3,
            'amount_pence' => 0,
            'balance_after_pence' => 1000,
            'meta' => ['run_id' => 'legacy_zero'],
            'idempotency_key' => 'apify:legacy_zero',
        ]);

        $summary = $this->billing->summary($user);
        $this->assertNotEmpty($summary['recent']);
        $this->assertTrue(collect($summary['recent'])->every(fn (array $row): bool => $row['amount_pence'] != 0));

        $charges = $this->billing->paginatedCharges($user);
        $this->assertTrue(collect($charges->items())->every(fn (array $row): bool => $row['amount_pence'] != 0));
    }

    public function test_estimate_nanogpt_uses_token_math_without_floor_clamp(): void
    {
        config([
            'billing.vendors.nanogpt.floors_usd.video_analysis' => 0.0005,
        ]);

        $tiny = $this->billing->estimateNanoGptChatUsd(
            2000,
            800,
            'deepseek/deepseek-v4-flash',
            'video_analysis',
        );
        // 2000 * 0.14/M + 800 * 0.28/M = 0.00028 + 0.000224 = 0.000504
        $this->assertEqualsWithDelta(0.000504, $tiny, 0.0000001);

        $large = $this->billing->estimateNanoGptChatUsd(
            2_000_000,
            500_000,
            'deepseek/deepseek-v4-flash',
            'video_analysis',
        );
        // 2M * 0.14/M + 0.5M * 0.28/M = 0.28 + 0.14 = 0.42
        $this->assertEqualsWithDelta(0.42, $large, 0.000001);
    }

    public function test_estimate_nanogpt_resolves_model_ids_that_contain_dots(): void
    {
        config([
            'billing.vendors.nanogpt.floors_usd.video_analysis' => 0.0005,
            'billing.vendors.nanogpt.models' => [
                'qwen3.7-flash' => [
                    'input_per_m_usd' => 0.10,
                    'output_per_m_usd' => 0.40,
                ],
            ],
        ]);

        // 45800 * 0.10/M + 924 * 0.40/M = 0.00458 + 0.0003696 = 0.0049496
        $estimate = $this->billing->estimateNanoGptChatUsd(
            45_800,
            924,
            'qwen3.7-flash',
            'video_analysis',
        );

        $this->assertEqualsWithDelta(0.0049496, $estimate, 0.0000001);
        $this->assertNotEqualsWithDelta(0.0005, $estimate, 0.0000001);
    }

    public function test_price_multiplier_default_is_one_point_seven_five(): void
    {
        // setUp pins 1.3 for rounding math; assert the committed config default (base + 75%).
        $source = file_get_contents(config_path('billing.php'));
        $this->assertNotFalse($source);
        $this->assertMatchesRegularExpression(
            "/'price_multiplier'\\s*=>\\s*\\(float\\)\\s*env\\('SNITCH_BILLING_PRICE_MULTIPLIER',\\s*1\\.75\\)/",
            $source,
        );
        $this->assertStringContainsString('SNITCH_BILLING_PRICE_MULTIPLIER=1.75', (string) file_get_contents(base_path('.env.example')));
    }

    private function subscribe(User $user): Subscription
    {
        return $user->subscriptions()->create([
            'type' => 'default',
            'stripe_id' => 'sub_'.uniqid(),
            'stripe_status' => 'active',
            'stripe_price' => 'price_platform_test',
            'quantity' => 1,
        ]);
    }
}
