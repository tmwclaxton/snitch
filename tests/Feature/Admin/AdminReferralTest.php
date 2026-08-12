<?php

namespace Tests\Feature\Admin;

use App\Enums\BillingVendor;
use App\Models\BrandProfile;
use App\Models\ReferralCode;
use App\Models\User;
use App\Services\Admin\AdminReferralService;
use App\Services\Billing\UsageBillingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\WithPlatformBilling;
use Tests\TestCase;

class AdminReferralTest extends TestCase
{
    use RefreshDatabase;
    use WithPlatformBilling;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'snitch.admin_emails' => [
                'admin@example.com',
            ],
        ]);
    }

    public function test_non_admin_cannot_access_referral_admin_routes(): void
    {
        $user = User::factory()->create(['email' => 'user@example.com']);
        BrandProfile::factory()->for($user)->create();
        $referral = ReferralCode::factory()->create();

        $this->actingAs($user)->get(route('admin.referrals.index'))->assertForbidden();
        $this->actingAs($user)->post(route('admin.referrals.store'), [
            'code' => 'blocked',
            'name' => 'Blocked',
        ])->assertForbidden();
        $this->actingAs($user)->get(route('admin.referrals.show', $referral))->assertForbidden();
        $this->actingAs($user)->patch(route('admin.referrals.update', $referral), [
            'name' => 'Nope',
        ])->assertForbidden();
    }

    public function test_admin_can_create_list_and_view_referral_detail(): void
    {
        $admin = User::factory()->create(['email' => 'admin@example.com']);
        BrandProfile::factory()->for($admin)->create();

        $this->actingAs($admin)
            ->post(route('admin.referrals.store'), [
                'code' => 'partner-one',
                'name' => 'Partner One',
                'notes' => 'Launch partner',
                'is_active' => true,
            ])
            ->assertRedirect(route('admin.referrals.index'));

        $referral = ReferralCode::query()->where('code', 'partner-one')->first();
        $this->assertNotNull($referral);

        $this->actingAs($admin)
            ->get(route('admin.referrals.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/Referrals')
                ->has('codes', 1)
                ->where('codes.0.code', 'partner-one')
                ->where('auth.user.is_admin', true));

        $this->actingAs($admin)
            ->get(route('admin.referrals.show', $referral))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/ReferralShow')
                ->where('referral.code', 'partner-one')
                ->has('kpis')
                ->has('signupsSeries')
                ->has('usageSeries')
                ->has('paymentsSeries')
                ->has('clicksVsSignupsSeries')
                ->has('users.data'));
    }

    public function test_detail_aggregates_only_users_for_that_code(): void
    {
        $alpha = ReferralCode::factory()->create(['code' => 'alpha']);
        $beta = ReferralCode::factory()->create(['code' => 'beta']);

        $alphaUser = User::factory()->create(['referral_code_id' => $alpha->id, 'email' => 'alpha@example.com']);
        User::factory()->create(['referral_code_id' => $beta->id, 'email' => 'beta@example.com']);

        $billing = app(UsageBillingService::class);
        $billing->charge($alphaUser, 'explore.search', BillingVendor::Snitch);
        $billing->creditSubscriptionBonus($alphaUser, 'subscription_bonus:invoice:alpha_1');

        $detail = app(AdminReferralService::class)->show($alpha);

        $this->assertSame(1, $detail['kpis']['signups']);
        $this->assertSame('alpha@example.com', $detail['users']['data'][0]['email']);
        $this->assertGreaterThan(0.0, $detail['kpis']['lifetime_usage_pence']);
        $this->assertSame(3000.0, $detail['kpis']['lifetime_payments_pence']);
    }

    public function test_admin_can_update_referral_metadata(): void
    {
        $admin = User::factory()->create(['email' => 'admin@example.com']);
        BrandProfile::factory()->for($admin)->create();

        $referral = ReferralCode::factory()->create([
            'code' => 'live',
            'name' => 'Old name',
            'is_active' => true,
        ]);

        $this->actingAs($admin)
            ->patch(route('admin.referrals.update', $referral), [
                'name' => 'New name',
                'notes' => 'Updated notes',
                'is_active' => false,
            ])
            ->assertRedirect();

        $referral->refresh();

        $this->assertSame('New name', $referral->name);
        $this->assertSame('Updated notes', $referral->notes);
        $this->assertFalse($referral->is_active);
    }
}
