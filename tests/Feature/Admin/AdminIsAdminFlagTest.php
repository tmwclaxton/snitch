<?php

namespace Tests\Feature\Admin;

use App\Enums\BillingVendor;
use App\Enums\Platform;
use App\Enums\TrackedAccountKind;
use App\Mcp\Support\McpAuth;
use App\Models\BrandProfile;
use App\Models\TrackedAccount;
use App\Models\User;
use App\Services\Billing\PlanEntitlementService;
use App\Services\Billing\UsageBillingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AdminIsAdminFlagTest extends TestCase
{
    use RefreshDatabase;

    public function test_is_admin_column_or_env_email_grants_admin(): void
    {
        config(['snitch.admin_emails' => ['env-admin@snitch.test']]);

        $columnAdmin = User::factory()->withoutPlatformSubscription()->freePlan()->create([
            'email' => 'column-admin@example.com',
            'is_admin' => true,
        ]);
        $envAdmin = User::factory()->withoutPlatformSubscription()->freePlan()->create([
            'email' => 'env-admin@snitch.test',
            'is_admin' => false,
        ]);
        $normal = User::factory()->withoutPlatformSubscription()->freePlan()->create([
            'email' => 'customer@example.com',
            'is_admin' => false,
        ]);

        $this->assertTrue($columnAdmin->isAdmin());
        $this->assertTrue($envAdmin->isAdmin());
        $this->assertFalse($normal->isAdmin());
    }

    public function test_migration_sets_danielsmyth_admin_by_email(): void
    {
        $user = User::factory()->create([
            'email' => 'DanielSmyth098@gmail.com',
            'is_admin' => false,
        ]);

        DB::table('migrations')
            ->where('migration', '2026_10_08_193411_set_danielsmyth_is_admin')
            ->delete();

        $this->artisan('migrate', [
            '--path' => 'database/migrations/2026_10_08_193411_set_danielsmyth_is_admin.php',
            '--force' => true,
        ])->assertSuccessful();

        $this->assertTrue($user->fresh()->is_admin);
        $this->assertTrue($user->fresh()->isAdmin());
    }

    public function test_migration_is_noop_when_email_missing(): void
    {
        DB::table('migrations')
            ->where('migration', '2026_10_08_193411_set_danielsmyth_is_admin')
            ->delete();

        $this->artisan('migrate', [
            '--path' => 'database/migrations/2026_10_08_193411_set_danielsmyth_is_admin.php',
            '--force' => true,
        ])->assertSuccessful();

        $this->assertSame(0, User::query()->where('is_admin', true)->count());
    }

    public function test_column_admin_with_expired_trial_and_no_credits_is_not_blocked(): void
    {
        config(['snitch.admin_emails' => []]);

        $user = User::factory()
            ->withoutPlatformSubscription()
            ->withoutStarterCredit()
            ->freePlan()
            ->create([
                'email' => 'danielsmyth098@gmail.com',
                'is_admin' => true,
            ]);
        BrandProfile::factory()->for($user)->create();
        TrackedAccount::factory()->for($user)->create([
            'platform' => Platform::Instagram,
            'kind' => TrackedAccountKind::Competitor,
            'is_own_account' => false,
        ]);

        $billing = app(UsageBillingService::class);
        $plans = app(PlanEntitlementService::class);

        $this->assertTrue($billing->hasOperatorBypass($user));
        $this->assertTrue($billing->canAccessProduct($user));
        $this->assertFalse($billing->paywallState($user)['blocked']);
        $this->assertSame(0.0, $billing->balancePence($user));
        $this->assertNull(McpAuth::requireProductAccess($user));

        $entry = $billing->charge($user, 'explore.view', BillingVendor::Snitch);
        $this->assertNotNull($entry);
        $this->assertSame(0.0, $billing->balancePence($user));

        $summary = $plans->sharedSummary($user);
        $this->assertSame('admin', $summary['plan']);
        $this->assertSame('Admin', $summary['plan_name']);
        $this->assertFalse($summary['on_trial']);
        $this->assertFalse($summary['can_upgrade']);
        $this->assertTrue($summary['can_run_billable']);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk();

        $this->actingAs($user)
            ->get(route('onboarding.show', ['step' => 'paywall']))
            ->assertRedirect(route('dashboard'));

        $this->actingAs($user)
            ->get(route('billing.edit'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('billing/Index')
                ->where('isAdmin', true)
                ->where('subscription.plan_name', 'Admin')
                ->where('subscription.on_trial', false)
                ->where('subscription.can_upgrade', false)
                ->where('subscription.paywall.blocked', false)
            );
    }
}
