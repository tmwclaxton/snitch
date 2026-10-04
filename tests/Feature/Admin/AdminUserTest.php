<?php

namespace Tests\Feature\Admin;

use App\Enums\BillingVendor;
use App\Models\BrandProfile;
use App\Models\McpToolInvocation;
use App\Models\ReferralCode;
use App\Models\User;
use App\Services\Admin\AdminUserService;
use App\Services\Billing\UsageBillingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AdminUserTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'snitch.admin_emails' => [
                'admin@example.com',
            ],
        ]);
    }

    public function test_non_admin_cannot_view_admin_users(): void
    {
        $user = User::factory()->create(['email' => 'user@example.com']);
        BrandProfile::factory()->for($user)->create();

        $this->actingAs($user)
            ->get(route('admin.users.index'))
            ->assertForbidden();
    }

    public function test_admin_can_list_users_with_filters(): void
    {
        $admin = User::factory()->create(['email' => 'admin@example.com', 'name' => 'Admin Person']);
        BrandProfile::factory()->for($admin)->create();

        $referral = ReferralCode::factory()->create(['code' => 'partner']);
        $referred = User::factory()->create([
            'email' => 'referred@example.com',
            'name' => 'Referred User',
            'referral_code_id' => $referral->id,
        ]);
        BrandProfile::factory()->for($referred)->create();

        app(UsageBillingService::class)->creditClaimBonus($referred);
        app(UsageBillingService::class)->charge($referred, 'explore.search', BillingVendor::Snitch);

        McpToolInvocation::query()->create([
            'user_id' => $referred->id,
            'tool' => 'whoami',
            'ok' => true,
            'duration_ms' => 5,
            'auth' => 'sanctum',
        ]);

        $this->actingAs($admin)
            ->get(route('admin.users.index', ['search' => 'referred']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/users/Index')
                ->has('users.data', 1)
                ->where('users.data.0.email', 'referred@example.com')
                ->where('users.data.0.referral_code', 'partner')
                ->where('users.data.0.last_activity_at', fn ($value) => $value !== null));
    }

    public function test_admin_can_view_user_profile_and_activity(): void
    {
        $admin = User::factory()->create(['email' => 'admin@example.com']);
        BrandProfile::factory()->for($admin)->create();

        $subject = User::factory()->create(['email' => 'subject@example.com']);
        BrandProfile::factory()->for($subject)->create();
        app(UsageBillingService::class)->creditClaimBonus($subject);
        app(UsageBillingService::class)->charge($subject, 'explore.search', BillingVendor::Snitch);

        McpToolInvocation::query()->create([
            'user_id' => $subject->id,
            'tool' => 'list_feed',
            'ok' => true,
            'duration_ms' => 8,
            'auth' => 'sanctum',
        ]);

        $this->actingAs($admin)
            ->get(route('admin.users.show', $subject))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/users/Show')
                ->where('user.email', 'subject@example.com')
                ->has('spendSeries.points')
                ->has('ledgerSeries')
                ->has('mcpSeries')
                ->has('activity')
                ->where('activity', fn ($rows) => count($rows) >= 2));
    }

    public function test_user_service_index_search_is_case_insensitive_on_email(): void
    {
        User::factory()->create(['email' => 'FindMe@Example.com']);

        $result = app(AdminUserService::class)->index(search: 'findme@');

        $this->assertSame(1, $result['users']['total']);
        $this->assertSame('FindMe@Example.com', $result['users']['data'][0]['email']);
    }

    public function test_users_index_headers_share_row_grid_and_right_align_balance(): void
    {
        $page = file_get_contents(resource_path('js/pages/admin/users/Index.vue'));

        $this->assertNotFalse($page);
        $this->assertStringContainsString('snitch-admin-users-row', $page);
        $this->assertStringContainsString('userRowClass', $page);
        $this->assertStringContainsString('gap-x-3', $page);
        $this->assertStringContainsString('minmax(0,1.5fr)', $page);
        $this->assertStringContainsString('minmax(2.75rem,0.35fr)', $page);
        $this->assertStringContainsString('<wbr', $page);
        $this->assertStringContainsString('whenLines', $page);
        $this->assertStringContainsString("formatPenceAsGbp(row.balance_pence, { decimals: 'always2' })", $page);
        $this->assertStringNotContainsString('overflow-x-auto', $page);
        $this->assertStringNotContainsString('min-w-[56rem]', $page);
        $this->assertStringContainsString('text-right text-sm font-medium', $page);
        $this->assertStringContainsString('text-right text-sm tabular-nums', $page);
        $this->assertStringContainsString('planLabel', $page);
        $this->assertStringContainsString("none: 'None'", $page);
        $this->assertStringNotContainsString('gap-x-0', $page);
        $this->assertStringNotContainsString('line-clamp', $page);
        $this->assertStringNotContainsString('truncate', $page);
        $this->assertStringNotContainsString('text-xs', $page);
        $this->assertStringNotContainsString('snitch-ink-label border-b', $page);
    }
}
