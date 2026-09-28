<?php

namespace Tests\Feature\Growth;

use App\Enums\Platform;
use App\Models\BrandProfile;
use App\Models\FollowerSnapshot;
use App\Models\MonthlyReport;
use App\Models\Post;
use App\Models\TrackedAccount;
use App\Models\User;
use App\Services\Growth\MonthlyReportBuilder;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class MonthlyReportBuilderTest extends TestCase
{
    use RefreshDatabase;

    public function test_report_formats_engagement_as_percent_and_uses_thousands_separators(): void
    {
        $user = User::factory()->create();
        BrandProfile::factory()->for($user)->create();

        $own = TrackedAccount::factory()->for($user)->create([
            'platform' => Platform::Instagram,
            'is_own_account' => true,
            'handle' => 'goodgym',
            'followers' => 1000,
        ]);

        $month = CarbonImmutable::now('Europe/London')->startOfMonth()->subMonth();

        FollowerSnapshot::factory()->create([
            'social_account_id' => $own->social_account_id,
            'followers' => 1000,
            'captured_on' => $month->addDays(2)->toDateString(),
        ]);

        // 109 likes + 20 comments = 129 / 1000 followers = 12.9%
        Post::factory()->forAccount($own)->create([
            'posted_at' => $month->addDays(5),
            'caption' => 'Full caption with enough words to prove we do not truncate mid-word for Dobble and Chess night.',
            'metrics' => ['views' => 2000, 'likes' => 109, 'comments' => 20, 'shares' => 9],
        ]);
        Post::factory()->forAccount($own)->create([
            'posted_at' => $month->addDays(10),
            'caption' => 'Second post',
            'metrics' => ['views' => 1500, 'likes' => 109, 'comments' => 20, 'shares' => 9],
        ]);

        $rival = TrackedAccount::factory()->for($user)->create([
            'platform' => Platform::Instagram,
            'is_own_account' => false,
            'handle' => 'rival',
            'followers' => 5230,
        ]);

        FollowerSnapshot::factory()->create([
            'social_account_id' => $rival->social_account_id,
            'followers' => 5230,
            'captured_on' => $month->addDays(3)->toDateString(),
        ]);

        Post::factory()->forAccount($rival)->create([
            'posted_at' => $month->addDays(6),
            'metrics' => ['views' => 800, 'likes' => 18, 'comments' => 4, 'shares' => 0],
        ]);

        $payload = app(MonthlyReportBuilder::class)->build($user, $month);

        $this->assertSame($month->format('F Y'), $payload['month_label']);
        $this->assertSame('12.9%', $payload['kpis']['engagement_rate']['you_display']);
        $this->assertStringContainsString('%', $payload['kpis']['engagement_rate']['peer_display']);
        $this->assertSame('5,230', $payload['kpis']['followers']['peer_display']);
        $this->assertStringContainsString('no data for', $payload['kpis']['posts']['you_change_label']);
        $this->assertNotEmpty($payload['own_top_posts']);
        $this->assertArrayHasKey('multiplier', $payload['own_top_posts'][0]);
        $captions = collect($payload['own_top_posts'])->pluck('caption')->implode(' ');
        $this->assertStringContainsString('Dobble and Chess', $captions);
        foreach ($payload['own_top_posts'] as $row) {
            $this->assertStringNotContainsString('…', (string) $row['caption']);
            $this->assertStringNotContainsString('…', (string) $row['caption_preview']);
        }
    }

    public function test_current_month_label_includes_so_far_and_is_default(): void
    {
        $user = User::factory()->create();
        BrandProfile::factory()->for($user)->create();

        $builder = app(MonthlyReportBuilder::class);
        $default = $builder->monthStart(null);
        $now = CarbonImmutable::now('Europe/London')->startOfMonth();

        $this->assertTrue($default->isSameMonth($now));
        $this->assertStringContainsString('(so far)', $builder->monthLabel($default));

        $options = $builder->monthOptions($user, $default);
        $this->assertSame($default->format('Y-m'), $options[0]['value']);
        $this->assertStringContainsString('(so far)', $options[0]['label']);
    }

    public function test_report_page_passes_month_options_and_display_fields(): void
    {
        $user = User::factory()->create();
        BrandProfile::factory()->for($user)->create();
        $own = TrackedAccount::factory()->for($user)->create([
            'platform' => Platform::Instagram,
            'is_own_account' => true,
            'followers' => 1000,
        ]);

        $month = CarbonImmutable::now('Europe/London')->startOfMonth()->subMonth();

        FollowerSnapshot::factory()->create([
            'social_account_id' => $own->social_account_id,
            'followers' => 1000,
            'captured_on' => $month->addDays(1)->toDateString(),
        ]);

        Post::factory()->forAccount($own)->create([
            'posted_at' => $month->addDays(4),
            'caption' => 'Report page caption stays whole',
            'metrics' => ['views' => 1000, 'likes' => 50, 'comments' => 10, 'shares' => 5],
        ]);

        $this->actingAs($user)
            ->get(route('growth.report', ['month' => $month->format('Y-m')]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('growth/Report')
                ->where('month', $month->format('Y-m'))
                ->has('months')
                ->where('report.kpis.engagement_rate.you_display', fn ($v): bool => is_string($v) && str_ends_with($v, '%'))
                ->where('report.own_top_posts.0.caption', 'Report page caption stays whole')
                ->has('report.own_top_posts.0.multiplier')
            );
    }

    public function test_pdf_uses_formatted_kpi_displays(): void
    {
        $user = User::factory()->create();
        BrandProfile::factory()->for($user)->create();
        $report = MonthlyReport::factory()->for($user)->create();

        $this->actingAs($user)
            ->get(route('growth.report.pdf', $report))
            ->assertOk()
            ->assertSee('2.5%', false)
            ->assertSee('1,200', false)
            ->assertDontSee('vs last month: -', false);
    }
}
