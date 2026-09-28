<?php

namespace Tests\Feature\Growth;

use App\Models\BrandProfile;
use App\Models\MonthlyReport;
use App\Models\ReportShareLink;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class MonthlyReportShareTest extends TestCase
{
    use RefreshDatabase;

    public function test_share_token_grants_public_read_access_and_can_be_revoked(): void
    {
        $user = User::factory()->create();
        BrandProfile::factory()->for($user)->create();
        $report = MonthlyReport::factory()->for($user)->create();

        $this->actingAs($user)
            ->post(route('growth.report.share', $report))
            ->assertRedirect();

        $link = ReportShareLink::query()
            ->where('monthly_report_id', $report->id)
            ->whereNull('revoked_at')
            ->first();

        $this->assertNotNull($link);
        $this->assertNotSame('', $link->token);

        $this->get(route('reports.public', ['token' => $link->token]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('growth/PublicReport')
                ->where('report.month_label', $report->payload['month_label'])
            );

        $this->actingAs($user)
            ->post(route('growth.report.revoke', $report))
            ->assertRedirect();

        $this->assertNotNull($link->fresh()->revoked_at);

        $this->get(route('reports.public', ['token' => $link->token]))
            ->assertNotFound();
    }

    public function test_guessable_or_unknown_token_is_not_found(): void
    {
        $this->get(route('reports.public', ['token' => str_repeat('a', 48)]))
            ->assertNotFound();
    }

    public function test_report_pdf_view_renders_for_owner(): void
    {
        $user = User::factory()->create();
        BrandProfile::factory()->for($user)->create();
        $report = MonthlyReport::factory()->for($user)->create();

        $this->actingAs($user)
            ->get(route('growth.report.pdf', $report))
            ->assertOk()
            ->assertSee('Snitch monthly report', false)
            ->assertSee($report->payload['month_label'], false);
    }
}
