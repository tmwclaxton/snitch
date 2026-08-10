<?php

namespace Tests\Feature\Billing;

use App\Enums\BillingVendor;
use App\Models\User;
use App\Services\Billing\UsageBillingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Cashier\Subscription;
use Tests\TestCase;

class BillingChargesTest extends TestCase
{
    use RefreshDatabase;

    private UsageBillingService $billing;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'billing.platform_stripe_price' => 'price_platform_test',
            'billing.price_multiplier' => 1.3,
            'billing.usd_to_gbp' => 1.0,
            'billing.min_run_balance_pence' => 20,
        ]);

        $this->billing = app(UsageBillingService::class);
    }

    public function test_guests_cannot_view_billing_charges(): void
    {
        $this->get(route('billing.charges'))->assertRedirect();
    }

    public function test_charges_page_is_paginated(): void
    {
        $user = User::factory()->create();
        $this->subscribe($user);
        $this->billing->creditFromTopUp($user, 50_000, 'topup:charges-page');

        for ($i = 0; $i < 30; $i++) {
            $this->billing->charge($user, 'analyze.post', BillingVendor::NanoGpt, 0.04);
        }

        $this->actingAs($user)
            ->get(route('billing.charges'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('billing/Charges')
                ->has('charges.data', UsageBillingService::CHARGES_PER_PAGE)
                ->where('charges.total', 31)
                ->where('charges.current_page', 1)
                ->where('charges.last_page', 2)
                ->has('filters')
                ->has('vendors')
                ->has('actions')
                ->where('usage.balance_pence', fn ($balance) => (float) $balance === $this->billing->balancePence($user)));

        $pageTwo = $this->actingAs($user)
            ->get(route('billing.charges', ['page' => 2]))
            ->assertOk();

        $pageTwo->assertInertia(fn (Assert $page) => $page
            ->component('billing/Charges')
            ->has('charges.data', 6)
            ->where('charges.current_page', 2)
            ->where('charges.path', '/billing/charges'));

        $this->assertPathOnlyChargeLinks($pageTwo->inertiaProps('charges.links'));
    }

    public function test_charges_pagination_links_stay_path_only_when_forwarded_proto_is_http(): void
    {
        $user = User::factory()->create();
        $this->subscribe($user);
        $this->billing->creditFromTopUp($user, 50_000, 'topup:charges-http-proto');

        for ($i = 0; $i < 30; $i++) {
            $this->billing->charge($user, 'analyze.post', BillingVendor::NanoGpt, 0.04);
        }

        $response = $this->actingAs($user)
            ->withServerVariables([
                'REMOTE_ADDR' => '127.0.0.1',
                'HTTP_HOST' => 'www.snitchsocial.net',
                'HTTPS' => 'off',
                'SERVER_PORT' => '80',
            ])
            ->withHeaders([
                'X-Forwarded-Proto' => 'http',
                'X-Forwarded-For' => '203.0.113.10',
            ])
            ->get('http://www.snitchsocial.net/billing/charges?page=2')
            ->assertOk();

        $response->assertInertia(fn (Assert $page) => $page
            ->component('billing/Charges')
            ->where('charges.current_page', 2)
            ->where('charges.path', '/billing/charges'));

        $this->assertPathOnlyChargeLinks($response->inertiaProps('charges.links'));
    }

    public function test_charges_page_filters_by_vendor_action_and_days(): void
    {
        $user = User::factory()->create();
        $this->subscribe($user);
        $this->billing->creditFromTopUp($user, 10_000, 'topup:filters');

        $this->billing->charge($user, 'sync.account', BillingVendor::Apify, 0.05);
        $this->billing->charge($user, 'analyze.post', BillingVendor::NanoGpt, 0.04);
        $this->billing->charge($user, 'brand.autofill', BillingVendor::Firecrawl, 0.01);

        $old = $this->billing->charge($user, 'analyze.post', BillingVendor::NanoGpt, 0.04);
        $old->forceFill(['created_at' => now()->subDays(40)])->save();

        $this->actingAs($user)
            ->get(route('billing.charges', ['vendor' => 'nanogpt']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.vendor', 'nanogpt')
                ->where('charges.total', 2)
                ->where('charges.data.0.vendor', 'nanogpt'));

        $this->actingAs($user)
            ->get(route('billing.charges', ['action' => 'sync.account']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.action', 'sync.account')
                ->where('charges.total', 1)
                ->where('charges.data.0.action', 'sync.account'));

        $this->actingAs($user)
            ->get(route('billing.charges', ['days' => 30, 'vendor' => 'nanogpt']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.days', 30)
                ->where('charges.total', 1));

        $filtered = $this->actingAs($user)
            ->get(route('billing.charges', ['vendor' => 'nanogpt', 'page' => 1]))
            ->assertOk();

        $filtered->assertInertia(fn (Assert $page) => $page
            ->where('filters.vendor', 'nanogpt')
            ->where('charges.path', '/billing/charges'));

        $this->assertPathOnlyChargeLinks(
            $filtered->inertiaProps('charges.links'),
            requiredQuery: 'vendor=nanogpt',
        );
    }

    public function test_nanogpt_charge_appears_at_centipence_precision(): void
    {
        config([
            'billing.usd_to_gbp' => 0.79,
            'billing.price_multiplier' => 1.3,
            'billing.actions.analyze.post.floor_usd' => 0.0005,
        ]);

        $user = User::factory()->create();
        $this->subscribe($user);
        $this->billing->creditFromTopUp($user, 1000, 'topup:nanogpt-display');
        // 0.0005 * 0.79 * 1.3 * 100 = 0.05135p → 0.05p
        $this->billing->charge($user, 'analyze.post', BillingVendor::NanoGpt, 0.0005);

        $this->actingAs($user)
            ->get(route('billing.charges', ['vendor' => 'nanogpt']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('charges.data.0.vendor', 'nanogpt')
                ->where('charges.data.0.amount_pence', -0.05)
                ->where('charges.data.0.balance_after_pence', 999.95));
    }

    public function test_billing_index_recent_charges_are_preview_only(): void
    {
        $user = User::factory()->create();
        $this->subscribe($user);
        $this->billing->creditFromTopUp($user, 50_000, 'topup:preview');

        for ($i = 0; $i < 12; $i++) {
            $this->billing->charge($user, 'analyze.post', BillingVendor::NanoGpt, 0.04);
        }

        $expectedTotal = 13;

        $this->actingAs($user)
            ->get(route('billing.edit'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('billing/Index')
                ->has('usage.recent', UsageBillingService::RECENT_PREVIEW_LIMIT)
                ->where('usage.recent_total', $expectedTotal)
                ->where('usage.recent_has_more', true));
    }

    public function test_charges_include_description_and_link_from_meta(): void
    {
        $user = User::factory()->create();
        $this->subscribe($user);
        $this->billing->creditFromTopUp($user, 10_000, 'topup:desc-link');

        $this->billing->charge(
            $user,
            'analyze.post',
            BillingVendor::NanoGpt,
            0.04,
            [
                'post_id' => 42,
                'platform' => 'youtube',
                'post_type' => 'reel',
            ],
        );

        $this->billing->charge(
            $user,
            'sync.account',
            BillingVendor::Apify,
            0.05,
            [
                'tracked_account_id' => 7,
                'platform' => 'instagram',
                'account_kind' => 'competitor',
                'handle' => 'rival',
            ],
        );

        $this->billing->charge(
            $user,
            'competitors.suggest',
            BillingVendor::Firecrawl,
            0.01,
            ['suggest_id' => 'sug-1', 'kind' => 'search'],
        );

        $this->actingAs($user)
            ->get(route('billing.charges'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('billing/Charges')
                ->where('charges.data.0.description', 'Suggested competitors')
                ->where('charges.data.0.link.type', 'competitors')
                ->where('charges.data.0.link.label', 'Competitors')
                ->where('charges.data.1.description', 'Synced Instagram competitor @rival')
                ->where('charges.data.1.link.type', 'tracked_account')
                ->where('charges.data.1.link.id', 7)
                ->where('charges.data.1.link.label', '@rival')
                ->where('charges.data.2.description', 'Analyzed YouTube Short')
                ->where('charges.data.2.link.type', 'post')
                ->where('charges.data.2.link.id', 42));

        $this->actingAs($user)
            ->get(route('billing.edit'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('billing/Index')
                ->where('usage.recent.0.description', 'Suggested competitors')
                ->where('usage.recent.0.link.type', 'competitors')
                ->where('usage.recent.2.description', 'Analyzed YouTube Short')
                ->where('usage.recent.2.link.id', 42));
    }

    public function test_legacy_charges_fall_back_to_action_description(): void
    {
        $user = User::factory()->create();
        $this->subscribe($user);
        $this->billing->creditFromTopUp($user, 1000, 'topup:legacy-desc');

        $this->billing->charge($user, 'analyze.post', BillingVendor::NanoGpt, 0.04);

        $this->actingAs($user)
            ->get(route('billing.charges'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('charges.data.0.description', 'Analyzed post')
                ->where('charges.data.0.link', null)
                ->where('charges.data.0.action', 'analyze.post'));
    }

    public function test_invalid_charge_filters_are_rejected(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('billing.charges', ['vendor' => 'not-a-vendor']))
            ->assertSessionHasErrors('vendor');

        $this->actingAs($user)
            ->get(route('billing.charges', ['days' => 14]))
            ->assertSessionHasErrors('days');
    }

    /**
     * @param  list<array{url: ?string, label: string, active: bool}>  $links
     */
    private function assertPathOnlyChargeLinks(array $links, ?string $requiredQuery = null): void
    {
        $this->assertNotEmpty($links);

        foreach ($links as $link) {
            $url = $link['url'] ?? null;

            if ($url === null) {
                continue;
            }

            $this->assertStringStartsWith('/billing/charges', $url);
            $this->assertStringNotContainsString('http://', $url);
            $this->assertStringNotContainsString('https://', $url);

            if ($requiredQuery !== null) {
                $this->assertStringContainsString($requiredQuery, $url);
            }
        }
    }

    private function subscribe(User $user): void
    {
        Subscription::query()->create([
            'user_id' => $user->id,
            'type' => config('billing.subscription_type', 'default'),
            'stripe_id' => 'sub_test_'.$user->id,
            'stripe_status' => 'active',
            'stripe_price' => 'price_platform_test',
            'quantity' => 1,
        ]);
    }
}
