<?php

namespace Tests\Feature\Billing;

use App\Enums\BillingVendor;
use App\Enums\Platform;
use App\Jobs\SyncTrackedAccountJob;
use App\Models\CreditLedgerEntry;
use App\Models\TrackedAccount;
use App\Models\User;
use App\Services\Apify\PlatformAdapterManager;
use App\Services\Billing\UsageBillingService;
use App\Services\Billing\VendorUsageCharger;
use App\Services\Scraping\ApifyMonthlyCapGate;
use App\Services\SnitchAnalyticsService;
use App\Services\TikHub\TikHubClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Cashier\Subscription;
use Tests\Concerns\WithPlatformBilling;
use Tests\TestCase;

class TikHubBillingTest extends TestCase
{
    use RefreshDatabase;
    use WithPlatformBilling;

    public function test_usage_summary_and_series_include_tikhub(): void
    {
        config([
            'billing.platform_stripe_price' => 'price_platform_test',
            'billing.price_multiplier' => 1.3,
            'billing.usd_to_gbp' => 1.0,
        ]);

        $billing = app(UsageBillingService::class);
        $user = User::factory()->withoutStarterCredit()->create();
        $this->subscribe($user);
        $billing->creditFromTopUp($user, 5000, 'topup:tikhub');

        $billing->charge($user, 'sync.account', BillingVendor::TikHub, 0.002);

        $summary = $billing->summary($user);
        $this->assertGreaterThan(0, $summary['vendors']['tikhub']['spend_pence']);

        $series = $billing->dailySpendSeries($user, 7);
        $today = collect($series['points'])->firstWhere('date', now()->toDateString());
        $this->assertNotNull($today);
        $this->assertGreaterThan(0, $today['tikhub']);
        $this->assertSame(
            $today['apify'] + $today['nanogpt'] + $today['firecrawl'] + $today['tikhub'] + $today['snitch'],
            $today['total'],
        );
    }

    public function test_sync_job_charges_tikhub_when_driver_is_tikhub(): void
    {
        Queue::fake();

        config([
            'snitch.apify.monthly_cap_usd' => 49,
            'snitch.tikhub.api_key' => 'tikhub-key',
            'snitch.tikhub.base_url' => 'https://api.tikhub.test',
            'snitch.sync.recency_days' => 30,
            'snitch.sync.posts_limit' => 3,
            'billing.vendors.tikhub.endpoints.instagram.floor_usd' => 0.002,
        ]);

        $user = User::factory()->withoutStarterCredit()->create();
        $this->enablePlatformBilling($user);

        CreditLedgerEntry::query()->create([
            'user_id' => $user->id,
            'action' => 'sync.account',
            'vendor' => BillingVendor::Apify,
            'cogs_usd' => 50,
            'multiplier' => 1.3,
            'amount_pence' => -100,
            'balance_after_pence' => 1000,
            'meta' => [],
            'idempotency_key' => 'seed-cap-'.uniqid(),
        ]);

        $account = TrackedAccount::factory()->for($user)->create([
            'platform' => Platform::Instagram,
            'handle' => 'nike',
            'external_id' => '123',
            'url' => 'https://instagram.com/nike',
            'display_name' => 'Nike',
        ]);

        Http::preventStrayRequests();
        Http::fake([
            'https://api.tikhub.test/api/v1/instagram/v2/fetch_user_info*' => Http::response([
                'code' => 200,
                'data' => [
                    'user' => [
                        'username' => 'nike',
                        'pk' => '123',
                        'full_name' => 'Nike',
                        'profile_pic_url' => 'https://cdn.example.com/avatar.jpg',
                    ],
                ],
            ]),
            'https://api.tikhub.test/api/v1/instagram/v2/fetch_user_reels*' => Http::response([
                'code' => 200,
                'data' => [
                    'items' => [
                        [
                            'code' => 'ABC123',
                            'url' => 'https://www.instagram.com/reel/ABC123/',
                            'taken_at' => now()->subDay()->timestamp,
                            'product_type' => 'clips',
                            'video_url' => 'https://cdn.example.com/reel.mp4',
                            'caption' => ['text' => 'Just do it'],
                            'like_count' => 10,
                            'comment_count' => 1,
                            'play_count' => 100,
                        ],
                    ],
                ],
            ]),
            'https://api.tikhub.test/api/v1/instagram/v2/fetch_user_posts*' => Http::response([
                'code' => 200,
                'data' => ['items' => []],
            ]),
        ]);

        $this->assertTrue(app(ApifyMonthlyCapGate::class)->shouldUseTikHub(Platform::Instagram));

        (new SyncTrackedAccountJob($account->id, force: true))->handle(
            app(PlatformAdapterManager::class),
            app(SnitchAnalyticsService::class),
            app(VendorUsageCharger::class),
        );

        $account->refresh();
        $this->assertSame('success', $account->last_sync_status);

        $this->assertTrue(
            CreditLedgerEntry::query()
                ->where('user_id', $user->id)
                ->where('vendor', BillingVendor::TikHub)
                ->where('action', 'sync.account')
                ->exists(),
        );

        // Costs should have been pulled (no leftover on singleton client).
        $this->assertSame([], app(TikHubClient::class)->pullRunCosts());
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
