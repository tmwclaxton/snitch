<?php

namespace Tests\Feature\Admin;

use App\Enums\BillingVendor;
use App\Models\BrandProfile;
use App\Models\McpToolInvocation;
use App\Models\User;
use App\Services\Admin\AdminActivityService;
use App\Services\Billing\UsageBillingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AdminActivityTest extends TestCase
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

    public function test_non_admin_cannot_view_admin_activity(): void
    {
        $user = User::factory()->create(['email' => 'user@example.com']);
        BrandProfile::factory()->for($user)->create();

        $this->actingAs($user)
            ->get(route('admin.activity'))
            ->assertForbidden();
    }

    public function test_admin_can_view_activity_with_charts_and_recent_events(): void
    {
        $admin = User::factory()->create(['email' => 'admin@example.com']);
        BrandProfile::factory()->for($admin)->create();

        $billing = app(UsageBillingService::class);
        $billing->creditClaimBonus($admin);
        $billing->charge($admin, 'explore.search', BillingVendor::Snitch);

        McpToolInvocation::query()->create([
            'user_id' => $admin->id,
            'tool' => 'list_feed',
            'ok' => true,
            'duration_ms' => 12,
            'auth' => 'sanctum',
        ]);

        $this->actingAs($admin)
            ->get(route('admin.activity'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/Activity')
                ->has('kpis')
                ->has('signupsSeries')
                ->has('ledgerSeries')
                ->has('mcpSeries')
                ->has('analysesSeries')
                ->has('recentEvents')
                ->where('auth.user.is_admin', true));
    }

    public function test_activity_service_returns_period_meta_and_kpis(): void
    {
        $user = User::factory()->create();
        app(UsageBillingService::class)->creditClaimBonus($user);

        $payload = app(AdminActivityService::class)->activity('day', 7);

        $this->assertSame('day', $payload['grain']);
        $this->assertArrayHasKey('signups', $payload['kpis']);
        $this->assertArrayHasKey('ledger_entries', $payload['kpis']);
        $this->assertNotEmpty($payload['recentEvents']);
    }
}
