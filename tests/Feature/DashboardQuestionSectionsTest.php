<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardQuestionSectionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_canvas_dashboard_keeps_vote_and_tracked_by_with_page_shortcuts(): void
    {
        $dashboard = file_get_contents(resource_path('js/pages/Dashboard.vue'));
        $sidebar = file_get_contents(resource_path('js/components/AppSidebar.vue'));

        $this->assertIsString($dashboard);
        $this->assertIsString($sidebar);

        $this->assertStringContainsString('PanelBigNumber', $dashboard);
        $this->assertStringContainsString('id="tracked-by"', $dashboard);
        $this->assertStringContainsString('id="vote"', $dashboard);
        $this->assertStringContainsString('scroll-mt-[4.5rem]', $dashboard);
        $this->assertStringNotContainsString('id="what-to-post"', $dashboard);
        $this->assertStringNotContainsString('id="performance"', $dashboard);
        $this->assertStringNotContainsString('id="ads"', $dashboard);

        $this->assertStringContainsString("title: 'Canvas'", $sidebar);
        $this->assertStringContainsString("title: 'What should we post?'", $sidebar);
        $this->assertStringContainsString('href: today()', $sidebar);
        $this->assertStringContainsString('href: growth()', $sidebar);
        $this->assertStringContainsString('href: competitors()', $sidebar);
        $this->assertStringContainsString("title: 'Vote on features'", $sidebar);
        $this->assertStringContainsString("title: 'Monthly report'", $sidebar);
        $this->assertStringNotContainsString("title: 'Winners'", $sidebar);

        $nav = file_get_contents(resource_path('js/components/NavMain.vue'));
        $menu = file_get_contents(resource_path('js/components/ui/sidebar/index.ts'));
        $this->assertIsString($nav);
        $this->assertIsString($menu);
        $this->assertStringContainsString('snitch-nav-current', $nav);
        $this->assertStringContainsString('h-3.5 gap-1 px-2 text-sm leading-none', $nav);
        $this->assertMatchesRegularExpression(
            '/<NavMain[\s\S]*?label="Dashboard"[\s\S]*?\/>/',
            $sidebar,
        );
        preg_match('/<NavMain[\s\S]*?label="Dashboard"[\s\S]*?\/>/', $sidebar, $dashboardGroup);
        $this->assertArrayHasKey(0, $dashboardGroup);
        $this->assertStringNotContainsString('collapsible', $dashboardGroup[0]);
        $this->assertStringContainsString('data-[active=true]:bg-[#fcd700]!', $menu);
        $this->assertStringContainsString('sm: "h-8 py-0 text-sm"', $menu);
    }

    public function test_resolve_active_dashboard_section_algorithm(): void
    {
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
            ['id' => 'tracked-by', 'top' => 100],
            ['id' => 'vote', 'top' => 400],
        ];

        $this->assertSame('tracked-by', $resolve($sections, [
            'viewportHeight' => 800,
            'scrollY' => 0,
            'scrollHeight' => 4000,
        ]));

        $this->assertSame('vote', $resolve($sections, [
            'viewportHeight' => 800,
            'scrollY' => 3195,
            'scrollHeight' => 4000,
        ]));
    }
}
