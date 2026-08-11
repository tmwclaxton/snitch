<?php

namespace Tests\Feature\Billing;

use App\Enums\BillingVendor;
use App\Enums\Platform;
use App\Jobs\SyncTrackedAccountJob;
use App\Models\CreditLedgerEntry;
use App\Models\TrackedAccount;
use App\Models\User;
use App\Services\Apify\ApifyClient;
use App\Services\Apify\PlatformAdapterManager;
use App\Services\Billing\VendorUsageCharger;
use App\Services\SnitchAnalyticsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\WithPlatformBilling;
use Tests\TestCase;

class ApifyBillingTest extends TestCase
{
    use RefreshDatabase;
    use WithPlatformBilling;

    public function test_apify_client_is_shared_singleton_for_run_cost_buffer(): void
    {
        $first = app(ApifyClient::class);
        $second = app(ApifyClient::class);

        $this->assertSame($first, $second);
    }

    public function test_sync_job_charges_apify_when_driver_is_apify(): void
    {
        Queue::fake();

        config([
            'snitch.apify.token' => 'secret-apify-token',
            'snitch.apify.base_url' => 'https://api.apify.test/v2',
            'snitch.apify.timeout' => 30,
            'snitch.apify.actors.facebook' => 'apify/facebook-posts-scraper',
            'snitch.apify.monthly_cap_usd' => 49,
            'snitch.tikhub.api_key' => '',
            'snitch.sync.recency_days' => 30,
            'snitch.sync.posts_limit' => 3,
        ]);

        $user = User::factory()->withoutStarterCredit()->create();
        $this->enablePlatformBilling($user);

        $account = TrackedAccount::factory()->for($user)->create([
            'platform' => Platform::Facebook,
            'handle' => 'rivalbakery',
            'external_id' => 'page_1',
            'url' => 'https://www.facebook.com/rivalbakery',
            'display_name' => 'Rival Bakery',
        ]);

        Http::preventStrayRequests();
        Http::fake([
            'https://api.apify.test/v2/acts/*/runs*' => Http::response([
                'data' => [
                    'id' => 'run_fb_1',
                    'defaultDatasetId' => 'dataset_fb_1',
                    'usageTotalUsd' => 0.05,
                ],
            ]),
            'https://api.apify.test/v2/datasets/dataset_fb_1/items*' => Http::response([
                [
                    'pageName' => 'Rival Bakery',
                    'pageId' => 'page_1',
                    'postId' => 'recent_reel',
                    'url' => 'https://facebook.com/rivalbakery/videos/1',
                    'text' => 'Fresh reel',
                    'time' => now()->subDays(2)->toIso8601String(),
                    'type' => 'video',
                    'isVideo' => true,
                    'videoUrl' => 'https://cdn.example.com/recent.mp4',
                    'likes' => 10,
                    'comments' => 1,
                    'shares' => 0,
                    'viewsCount' => 100,
                ],
            ]),
            'https://api.apify.test/v2/actor-runs/run_fb_1' => Http::response([
                'data' => [
                    'id' => 'run_fb_1',
                    'defaultDatasetId' => 'dataset_fb_1',
                    'usageTotalUsd' => 0.05,
                ],
            ]),
        ]);

        // force=false keeps resolveProfile skipped when profile fields are present.
        (new SyncTrackedAccountJob($account->id, force: false))->handle(
            app(PlatformAdapterManager::class),
            app(SnitchAnalyticsService::class),
            app(VendorUsageCharger::class),
        );

        $account->refresh();
        $this->assertSame('success', $account->last_sync_status);

        $this->assertTrue(
            CreditLedgerEntry::query()
                ->where('user_id', $user->id)
                ->where('vendor', BillingVendor::Apify)
                ->where('action', 'sync.account')
                ->where('cogs_usd', 0.05)
                ->exists(),
        );

        $this->assertSame([], app(ApifyClient::class)->pullRunCosts());
    }
}
