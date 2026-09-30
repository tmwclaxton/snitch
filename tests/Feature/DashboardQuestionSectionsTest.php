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

        $this->assertStringContainsString('resolveActiveDashboardSection', $scrollSpy);
        $this->assertStringContainsString('DASHBOARD_SPY_MARKER_RATIO = 0.3', $scrollSpy);
        $this->assertStringContainsString('scrollY >= maxScroll - epsilon', $scrollSpy);
        $this->assertStringContainsString('activateDashboardSection', $scrollSpy);
        $this->assertStringContainsString('activateDashboardSection', file_get_contents(
            resource_path('js/components/NavMain.vue'),
        ) ?: '');
        $this->assertStringContainsString('useDashboardScrollSpy(sectionIds)', $dashboard);

        $this->assertStringContainsString('See full brief', $dashboard);
        $this->assertStringContainsString('executive?.ads?.headline', $dashboard);
        $this->assertStringContainsString('ads_panel', $dashboard);
        $this->assertStringNotContainsString("title: 'Winners'", $sidebar);
        $this->assertStringContainsString("title: 'Monthly report'", $sidebar);
        $this->assertStringContainsString('activeDashboardSection', file_get_contents(
            resource_path('js/components/NavMain.vue'),
        ) ?: '');
    }

    public function test_resolve_active_dashboard_section_algorithm(): void
    {
        // Mirror the TS picker so the 30% marker + bottom-lock stay covered in CI.
        $resolve = static function (array $sections, array $options): ?string {
            if ($sections === []) {
                return null;
            }

            $viewportHeight = max(1, (int) $options['viewportHeight']);
            $marker = $viewportHeight * 0.3;
            $epsilon = 8;
            $maxScroll = max(0, (int) $options['scrollHeight'] - $viewportHeight);

            if ((int) $options['scrollY'] >= $maxScroll - $epsilon) {
                return $sections[array_key_last($sections)]['id'];
            }

            $active = $sections[0]['id'];

            foreach ($sections as $section) {
                if ($section['top'] <= $marker) {
                    $active = $section['id'];
                }
            }

            return $active;
        };

        $sections = [
            ['id' => 'what-to-post', 'top' => 100],
            ['id' => 'performance', 'top' => 400],
            ['id' => 'ads', 'top' => 900],
            ['id' => 'vote', 'top' => 1200],
        ];

        $this->assertSame('what-to-post', $resolve($sections, [
            'viewportHeight' => 800,
            'scrollY' => 0,
            'scrollHeight' => 4000,
        ]));

        // Marker at 240px: performance top (200) has crossed; ads (500) has not.
        $mid = [
            ['id' => 'what-to-post', 'top' => -200],
            ['id' => 'performance', 'top' => 200],
            ['id' => 'ads', 'top' => 500],
            ['id' => 'vote', 'top' => 900],
        ];
        $this->assertSame('performance', $resolve($mid, [
            'viewportHeight' => 800,
            'scrollY' => 600,
            'scrollHeight' => 4000,
        ]));

        // Short ads section: only its top has crossed the marker.
        $ads = [
            ['id' => 'what-to-post', 'top' => -900],
            ['id' => 'performance', 'top' => -400],
            ['id' => 'ads', 'top' => 100],
            ['id' => 'vote', 'top' => 700],
        ];
        $this->assertSame('ads', $resolve($ads, [
            'viewportHeight' => 800,
            'scrollY' => 1400,
            'scrollHeight' => 4000,
        ]));

        // Near document bottom → last section even if its top is still below the marker.
        $bottom = [
            ['id' => 'what-to-post', 'top' => -2000],
            ['id' => 'performance', 'top' => -1200],
            ['id' => 'ads', 'top' => -400],
            ['id' => 'vote', 'top' => 500],
        ];
        $this->assertSame('vote', $resolve($bottom, [
            'viewportHeight' => 800,
            'scrollY' => 3195,
            'scrollHeight' => 4000,
        ]));
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
