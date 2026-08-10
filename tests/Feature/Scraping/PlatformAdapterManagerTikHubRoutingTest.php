<?php

namespace Tests\Feature\Scraping;

use App\Enums\BillingVendor;
use App\Enums\Platform;
use App\Models\CreditLedgerEntry;
use App\Models\User;
use App\Services\Apify\Adapters\FacebookAdapter;
use App\Services\Apify\Adapters\InstagramAdapter as ApifyInstagramAdapter;
use App\Services\Apify\PlatformAdapterManager;
use App\Services\Scraping\ApifyMonthlyCapGate;
use App\Services\TikHub\Adapters\InstagramAdapter as TikHubInstagramAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class PlatformAdapterManagerTikHubRoutingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        config([
            'snitch.apify.monthly_cap_usd' => 49,
            'snitch.tikhub.api_key' => 'tikhub-test',
        ]);
    }

    public function test_under_cap_uses_apify_instagram_adapter(): void
    {
        $manager = app(PlatformAdapterManager::class);

        $this->assertInstanceOf(ApifyInstagramAdapter::class, $manager->for(Platform::Instagram));
        $this->assertSame('apify', $manager->driverFor(Platform::Instagram));
        $this->assertSame('apify', $manager->driverFor(Platform::Facebook));
    }

    public function test_over_cap_uses_tikhub_instagram_adapter(): void
    {
        $this->seedApifyCogs(50);

        $manager = app(PlatformAdapterManager::class);

        $this->assertInstanceOf(TikHubInstagramAdapter::class, $manager->for(Platform::Instagram));
        $this->assertSame('tikhub', $manager->driverFor(Platform::Instagram));
    }

    public function test_over_cap_keeps_facebook_on_apify(): void
    {
        $this->seedApifyCogs(50);

        $manager = app(PlatformAdapterManager::class);

        $this->assertInstanceOf(FacebookAdapter::class, $manager->for(Platform::Facebook));
        $this->assertSame('apify', $manager->driverFor(Platform::Facebook));
    }

    public function test_hard_exhaust_routes_instagram_to_tikhub_and_facebook_to_apify(): void
    {
        app(ApifyMonthlyCapGate::class)->markHardExhausted();

        $manager = app(PlatformAdapterManager::class);

        $this->assertInstanceOf(TikHubInstagramAdapter::class, $manager->for(Platform::Instagram));
        $this->assertSame('tikhub', $manager->driverFor(Platform::Instagram));
        $this->assertInstanceOf(FacebookAdapter::class, $manager->for(Platform::Facebook));
        $this->assertSame('apify', $manager->driverFor(Platform::Facebook));
    }

    public function test_missing_tikhub_key_keeps_apify_when_over_cap(): void
    {
        config(['snitch.tikhub.api_key' => '']);
        $this->seedApifyCogs(50);

        $manager = app(PlatformAdapterManager::class);

        $this->assertInstanceOf(ApifyInstagramAdapter::class, $manager->for(Platform::Instagram));
        $this->assertSame('apify', $manager->driverFor(Platform::Instagram));
        $this->assertSame('apify', $manager->driverFor(Platform::Facebook));
    }

    public function test_missing_tikhub_key_keeps_apify_when_hard_exhausted(): void
    {
        config(['snitch.tikhub.api_key' => '']);
        app(ApifyMonthlyCapGate::class)->markHardExhausted();

        $manager = app(PlatformAdapterManager::class);

        $this->assertInstanceOf(ApifyInstagramAdapter::class, $manager->for(Platform::Instagram));
        $this->assertSame('apify', $manager->driverFor(Platform::Instagram));
        $this->assertSame('apify', $manager->driverFor(Platform::Facebook));
    }

    private function seedApifyCogs(float $cogsUsd): void
    {
        $user = User::factory()->create();

        CreditLedgerEntry::query()->create([
            'user_id' => $user->id,
            'action' => 'sync.account',
            'vendor' => BillingVendor::Apify,
            'cogs_usd' => $cogsUsd,
            'multiplier' => 1.4,
            'amount_pence' => -100,
            'balance_after_pence' => 0,
            'meta' => [],
            'idempotency_key' => 'cap-routing-'.uniqid(),
        ]);
    }
}
