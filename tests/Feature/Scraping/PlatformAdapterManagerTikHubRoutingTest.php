<?php

namespace Tests\Feature\Scraping;

use App\Enums\BillingVendor;
use App\Enums\Platform;
use App\Models\CreditLedgerEntry;
use App\Models\User;
use App\Services\Apify\Adapters\InstagramAdapter as ApifyInstagramAdapter;
use App\Services\Apify\PlatformAdapterManager;
use App\Services\Scraping\ApifyMonthlyCapGate;
use App\Services\TikHub\Adapters\InstagramAdapter as TikHubInstagramAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Tests\TestCase;

class PlatformAdapterManagerTikHubRoutingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_under_cap_uses_apify_instagram_adapter(): void
    {
        config([
            'snitch.apify.monthly_cap_usd' => 49,
            'snitch.tikhub.api_key' => 'tikhub-test',
        ]);

        $manager = app(PlatformAdapterManager::class);

        $this->assertInstanceOf(ApifyInstagramAdapter::class, $manager->for(Platform::Instagram));
        $this->assertSame('apify', $manager->driverFor(Platform::Instagram));
    }

    public function test_over_cap_uses_tikhub_instagram_adapter(): void
    {
        config([
            'snitch.apify.monthly_cap_usd' => 49,
            'snitch.tikhub.api_key' => 'tikhub-test',
        ]);

        $user = User::factory()->create();
        CreditLedgerEntry::query()->create([
            'user_id' => $user->id,
            'action' => 'sync.account',
            'vendor' => BillingVendor::Apify,
            'cogs_usd' => 50,
            'multiplier' => 1.4,
            'amount_pence' => -100,
            'balance_after_pence' => 0,
            'meta' => [],
            'idempotency_key' => 'cap-routing-'.uniqid(),
        ]);

        $manager = app(PlatformAdapterManager::class);

        $this->assertInstanceOf(TikHubInstagramAdapter::class, $manager->for(Platform::Instagram));
        $this->assertSame('tikhub', $manager->driverFor(Platform::Instagram));
    }

    public function test_facebook_throws_when_apify_exhausted(): void
    {
        config([
            'snitch.apify.monthly_cap_usd' => 49,
            'snitch.tikhub.api_key' => 'tikhub-test',
        ]);

        app(ApifyMonthlyCapGate::class)->markHardExhausted();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Facebook sync is unavailable');

        app(PlatformAdapterManager::class)->for(Platform::Facebook);
    }
}
