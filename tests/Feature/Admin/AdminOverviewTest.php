<?php

namespace Tests\Feature\Admin;

use App\Enums\AnalysisStatus;
use App\Enums\BillingVendor;
use App\Models\BrandProfile;
use App\Models\CreditLedgerEntry;
use App\Models\Post;
use App\Models\PostAnalysis;
use App\Models\User;
use App\Services\Billing\UsageBillingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AdminOverviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'snitch.admin_emails' => [
                'admin@example.com',
                'tmwclaxton@gmail.com',
            ],
        ]);
    }

    public function test_non_admin_cannot_view_admin_overview(): void
    {
        $user = User::factory()->create(['email' => 'user@example.com']);
        BrandProfile::factory()->for($user)->create();

        $this->actingAs($user)
            ->get(route('admin.overview'))
            ->assertForbidden();
    }

    public function test_admin_can_view_overview_with_key_props(): void
    {
        $admin = User::factory()->create(['email' => 'admin@example.com']);
        BrandProfile::factory()->for($admin)->create();

        $billing = app(UsageBillingService::class);
        $billing->creditClaimBonus($admin);
        $billing->charge($admin, 'explore.search', BillingVendor::Snitch);

        $post = Post::factory()->create();
        PostAnalysis::factory()->for($post)->create([
            'status' => AnalysisStatus::Failed,
            'error_message' => 'boom',
        ]);

        CreditLedgerEntry::query()->where('user_id', $admin->id)->where('amount_pence', '<', 0)->update([
            'cogs_usd' => 0.01,
            'multiplier' => 1.3,
        ]);

        $this->actingAs($admin)
            ->get(route('admin.overview'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/Overview')
                ->has('kpis')
                ->where('kpis.users_total', 1)
                ->has('spendSeries.points')
                ->has('profit')
                ->has('platforms')
                ->has('failedAnalyses')
                ->has('mcpTools')
                ->has('topActions')
                ->where('auth.user.is_admin', true));
    }

    public function test_shared_auth_marks_non_admin(): void
    {
        $user = User::factory()->create(['email' => 'user@example.com']);
        BrandProfile::factory()->for($user)->create();

        $this->actingAs($user)
            ->get(route('billing.edit'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('auth.user.is_admin', false));
    }
}
