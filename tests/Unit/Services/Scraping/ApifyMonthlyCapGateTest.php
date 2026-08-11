<?php

namespace Tests\Unit\Services\Scraping;

use App\Enums\BillingVendor;
use App\Enums\Platform;
use App\Models\CreditLedgerEntry;
use App\Models\User;
use App\Services\Scraping\ApifyMonthlyCapGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class ApifyMonthlyCapGateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        config([
            'snitch.apify.monthly_cap_usd' => 49,
            'snitch.tikhub.api_key' => 'test-tikhub-key',
        ]);
    }

    public function test_under_cap_does_not_use_tikhub(): void
    {
        $this->seedApifyCogs(10.0);

        $gate = app(ApifyMonthlyCapGate::class);

        $this->assertFalse($gate->isApifyExhausted());
        $this->assertFalse($gate->shouldUseTikHub(Platform::Instagram));
        $this->assertFalse($gate->shouldUseTikHub(Platform::Facebook));
        $this->assertSame(39.0, $gate->remainingUsd());
    }

    public function test_at_cap_uses_tikhub_for_supported_platforms(): void
    {
        $this->seedApifyCogs(49.0);

        $gate = app(ApifyMonthlyCapGate::class);

        $this->assertTrue($gate->isApifyExhausted());
        $this->assertTrue($gate->shouldUseTikHub(Platform::Instagram));
        $this->assertTrue($gate->shouldUseTikHub(Platform::TikTok));
        $this->assertTrue($gate->shouldUseTikHub(Platform::Youtube));
        $this->assertTrue($gate->shouldUseTikHub(Platform::LinkedIn));
        $this->assertSame(0.0, $gate->remainingUsd());
    }

    public function test_over_cap_keeps_apify_for_facebook(): void
    {
        $this->seedApifyCogs(100.0);

        $gate = app(ApifyMonthlyCapGate::class);

        $this->assertTrue($gate->isApifyExhausted());
        $this->assertFalse($gate->tikHubSupports(Platform::Facebook));
        $this->assertFalse($gate->shouldUseTikHub(Platform::Facebook));
    }

    public function test_zero_cap_soft_exhausts_and_uses_tikhub_when_supported(): void
    {
        config(['snitch.apify.monthly_cap_usd' => 0]);

        $gate = app(ApifyMonthlyCapGate::class);

        $this->assertTrue($gate->isApifyExhausted());
        $this->assertTrue($gate->shouldUseTikHub(Platform::Instagram));
        $this->assertTrue($gate->shouldUseTikHub(Platform::TikTok));
        $this->assertTrue($gate->shouldUseTikHub(Platform::Youtube));
        $this->assertTrue($gate->shouldUseTikHub(Platform::LinkedIn));
        $this->assertNull($gate->remainingUsd());
    }

    public function test_zero_cap_keeps_apify_for_facebook(): void
    {
        config(['snitch.apify.monthly_cap_usd' => 0]);

        $gate = app(ApifyMonthlyCapGate::class);

        $this->assertTrue($gate->isApifyExhausted());
        $this->assertFalse($gate->shouldUseTikHub(Platform::Facebook));
    }

    public function test_zero_cap_without_tikhub_key_keeps_apify(): void
    {
        config([
            'snitch.apify.monthly_cap_usd' => 0,
            'snitch.tikhub.api_key' => '',
        ]);

        $gate = app(ApifyMonthlyCapGate::class);

        $this->assertTrue($gate->isApifyExhausted());
        $this->assertFalse($gate->shouldUseTikHub(Platform::Instagram));
        $this->assertFalse($gate->shouldUseTikHub(Platform::Facebook));
    }

    public function test_hard_exhaust_uses_tikhub_when_supported_even_under_soft_cap(): void
    {
        $this->seedApifyCogs(1.0);

        $gate = app(ApifyMonthlyCapGate::class);
        $gate->markHardExhausted();

        $this->assertTrue($gate->isApifyExhausted());
        $this->assertTrue($gate->shouldUseTikHub(Platform::Instagram));
        $this->assertTrue($gate->shouldUseTikHub(Platform::TikTok));
        $this->assertTrue($gate->shouldUseTikHub(Platform::Youtube));
        $this->assertTrue($gate->shouldUseTikHub(Platform::LinkedIn));
    }

    public function test_hard_exhaust_keeps_apify_for_facebook(): void
    {
        $gate = app(ApifyMonthlyCapGate::class);
        $gate->markHardExhausted();

        $this->assertTrue($gate->isApifyExhausted());
        $this->assertFalse($gate->shouldUseTikHub(Platform::Facebook));
    }

    public function test_without_tikhub_key_never_routes_to_tikhub_when_soft_exhausted(): void
    {
        config(['snitch.tikhub.api_key' => '']);
        $this->seedApifyCogs(100.0);

        $gate = app(ApifyMonthlyCapGate::class);

        $this->assertTrue($gate->isApifyExhausted());
        $this->assertFalse($gate->shouldUseTikHub(Platform::Instagram));
        $this->assertFalse($gate->shouldUseTikHub(Platform::Facebook));
    }

    public function test_without_tikhub_key_never_routes_to_tikhub_when_hard_exhausted(): void
    {
        config(['snitch.tikhub.api_key' => '']);

        $gate = app(ApifyMonthlyCapGate::class);
        $gate->markHardExhausted();

        $this->assertTrue($gate->isApifyExhausted());
        $this->assertFalse($gate->shouldUseTikHub(Platform::Instagram));
        $this->assertFalse($gate->shouldUseTikHub(Platform::Facebook));
    }

    public function test_looks_like_quota_failure(): void
    {
        $gate = app(ApifyMonthlyCapGate::class);

        $this->assertTrue($gate->looksLikeQuotaFailure(402, 'Payment required'));
        $this->assertTrue($gate->looksLikeQuotaFailure(403, 'Monthly usage limit exceeded'));
        $this->assertFalse($gate->looksLikeQuotaFailure(403, 'Forbidden for this actor'));
        $this->assertFalse($gate->looksLikeQuotaFailure(500, 'boom'));
    }

    private function seedApifyCogs(float $cogsUsd): void
    {
        $user = User::factory()->create();

        CreditLedgerEntry::query()->create([
            'user_id' => $user->id,
            'action' => 'sync.account',
            'vendor' => BillingVendor::Apify,
            'cogs_usd' => $cogsUsd,
            'multiplier' => 1.3,
            'amount_pence' => -100,
            'balance_after_pence' => 0,
            'meta' => [],
            'idempotency_key' => 'test-apify-cogs-'.uniqid(),
        ]);
    }
}
