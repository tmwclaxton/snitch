<?php

namespace Tests\Feature\Billing;

use App\Enums\BillingVendor;
use App\Models\User;
use App\Services\Billing\UsageBillingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Cashier\Subscription;
use Tests\TestCase;

class BillingCustomerChargesTest extends TestCase
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
            'snitch.admin_emails' => ['admin@snitch.test'],
        ]);

        $this->billing = app(UsageBillingService::class);
    }

    public function test_customer_billing_hides_vendor_names_and_groups_credits(): void
    {
        $user = User::factory()->withoutStarterCredit()->create([
            'email' => 'customer@example.com',
        ]);
        $this->subscribe($user);
        $this->billing->creditFromTopUp($user, 50_000, 'topup:customer-view');

        for ($i = 0; $i < 5; $i++) {
            $this->billing->charge(
                $user,
                'analyze.post',
                BillingVendor::NanoGpt,
                0.05,
                ['handle' => 'goodgym'],
            );
        }

        $this->billing->charge(
            $user,
            'sync.account',
            BillingVendor::Apify,
            0.1,
            ['handle' => 'goodgym'],
        );

        $this->actingAs($user)
            ->get(route('billing.edit'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('billing/Index')
                ->where('isAdmin', false)
                ->where('spendSeries', null)
                ->has('usage.customer_recent')
                ->where('usage.customer_recent', function ($rows): bool {
                    $descriptions = collect($rows)->pluck('description')->implode(' | ');

                    return ! str_contains(strtolower($descriptions), 'apify')
                        && ! str_contains(strtolower($descriptions), 'nanogpt')
                        && ! str_contains(strtolower($descriptions), 'firecrawl')
                        && ! str_contains(strtolower($descriptions), 'tikhub')
                        && (
                            str_contains($descriptions, 'Post analysis')
                            || str_contains($descriptions, 'Weekly sync')
                        );
                })
            );

        $this->actingAs($user)
            ->get(route('billing.charges'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('billing/Charges')
                ->where('isAdmin', false)
                ->where('vendors', [])
                ->missing('charges')
                ->loadDeferredProps('default', fn (Assert $page) => $page
                    ->has('charges.data')
                    ->where('charges.data', function ($rows): bool {
                        foreach ($rows as $row) {
                            $text = strtolower((string) ($row['description'] ?? ''));

                            if (
                                str_contains($text, 'apify')
                                || str_contains($text, 'nanogpt')
                                || str_contains($text, 'firecrawl')
                                || str_contains($text, 'tikhub')
                            ) {
                                return false;
                            }

                            if (! isset($row['credits'])) {
                                return false;
                            }
                        }

                        return count($rows) > 0;
                    })
                )
            );
    }

    public function test_admin_billing_still_exposes_vendors(): void
    {
        $user = User::factory()->withoutStarterCredit()->create([
            'email' => 'admin@snitch.test',
        ]);
        $this->subscribe($user);
        $this->billing->creditFromTopUp($user, 10_000, 'topup:admin-view');
        $this->billing->charge($user, 'analyze.post', BillingVendor::NanoGpt, 0.05);

        $this->actingAs($user)
            ->get(route('billing.edit'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('billing/Index')
                ->where('isAdmin', true)
                ->has('usage.recent')
            );

        $this->actingAs($user)
            ->get(route('billing.charges'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('billing/Charges')
                ->where('isAdmin', true)
                ->has('vendors')
            );
    }

    private function subscribe(User $user): void
    {
        Subscription::query()->create([
            'user_id' => $user->id,
            'type' => 'default',
            'stripe_id' => 'sub_test_'.$user->id,
            'stripe_status' => 'active',
            'stripe_price' => 'price_platform_test',
            'quantity' => 1,
        ]);
    }
}
