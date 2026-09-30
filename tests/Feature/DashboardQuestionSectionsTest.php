<?php

namespace Tests\Feature;

use App\Enums\Platform;
use App\Models\BrandProfile;
use App\Models\TrackedAccount;
use App\Models\User;
use App\Models\WeeklyBrief;
use App\Services\Brief\WeeklyBriefGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DashboardQuestionSectionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_exposes_question_section_anchors_and_ads_panel(): void
    {
        $dashboard = file_get_contents(resource_path('js/pages/Dashboard.vue'));
        $sidebar = file_get_contents(resource_path('js/components/AppSidebar.vue'));
        $scrollSpy = file_get_contents(resource_path('js/composables/useDashboardScrollSpy.ts'));

        $this->assertIsString($dashboard);
        $this->assertIsString($sidebar);
        $this->assertIsString($scrollSpy);

        foreach (['what-to-post', 'performance', 'ads', 'tracked-by', 'vote'] as $id) {
            $this->assertStringContainsString("id=\"{$id}\"", $dashboard);
            $this->assertStringContainsString("'{$id}'", $scrollSpy);
        }

        $this->assertStringContainsString('What the data shows', $dashboard);
        $this->assertStringContainsString('See full brief', $dashboard);
        $this->assertStringContainsString('No ads found for your competitors', $dashboard);
        $this->assertStringContainsString('ads_panel', $dashboard);
        $this->assertStringNotContainsString("title: 'Winners'", $sidebar);
        $this->assertStringContainsString("title: 'Monthly report'", $sidebar);
        $this->assertStringContainsString('activeDashboardSection', file_get_contents(
            resource_path('js/components/NavMain.vue'),
        ) ?: '');
    }

    public function test_dashboard_brief_teaser_includes_best_times(): void
    {
        $user = User::factory()->create();
        BrandProfile::factory()->for($user)->create();
        $brief = WeeklyBrief::factory()->for($user)->create([
            'status' => 'ready',
            'week_start' => app(WeeklyBriefGenerator::class)->currentWeekStart()->toDateString(),
            'best_times' => [
                ['label' => 'Tue 18:00', 'score' => 2.4],
                ['label' => 'Thu 12:00', 'score' => 1.8],
            ],
        ]);
        $brief->ideas()->create([
            'position' => 1,
            'format' => 'Reel',
            'hook' => 'Open on proof',
            'caption_angle' => 'Angle',
            'cta' => 'CTA',
            'hashtags' => ['#a', '#b', '#c'],
            'recommended_day' => 'Mon',
            'recommended_hour' => 9,
            'inspired_by_post_ids' => [],
            'why' => 'Because',
        ]);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard')
                ->where('weekly_brief.id', $brief->id)
                ->where('weekly_brief.best_times.0.label', 'Tue 18:00')
                ->where('weekly_brief.best_times.0.score', 2.4)
            );
    }

    public function test_dashboard_ads_panel_empty_recommendation_when_no_ads(): void
    {
        $user = User::factory()->create();
        BrandProfile::factory()->for($user)->create();
        TrackedAccount::factory()->for($user)->forPlatform(Platform::Instagram)->create([
            'handle' => 'quietclub',
        ]);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard')
                ->loadDeferredProps('panel', fn (Assert $panel) => $panel
                    ->where('ads_panel.running_ads', 0)
                    ->where('ads_panel.accounts', [])
                    ->where(
                        'ads_panel.recommendation',
                        'None of your rivals advertise, so organic is enough for now.',
                    )
                )
            );
    }
}
