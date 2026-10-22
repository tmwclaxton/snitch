<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardQuestionSectionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_is_executive_brief_without_legacy_sections(): void
    {
        $dashboard = file_get_contents(resource_path('js/pages/ExecutiveBrief.vue'));
        $sidebar = file_get_contents(resource_path('js/components/AppSidebar.vue'));

        $this->assertIsString($dashboard);
        $this->assertIsString($sidebar);

        $this->assertStringContainsString('Executive Brief', $dashboard);
        $this->assertStringNotContainsString('TrackedBySection', $dashboard);
        $this->assertStringNotContainsString('VoteSection', $dashboard);
        $this->assertStringNotContainsString('id="tracked-by"', $dashboard);
        $this->assertStringNotContainsString('id="vote"', $dashboard);

        $this->assertStringContainsString("title: 'Executive Brief'", $sidebar);
        $this->assertStringContainsString("title: 'Chat'", $sidebar);
        $this->assertStringContainsString("title: 'Competitors'", $sidebar);
        $this->assertStringContainsString("title: 'Connect your AI'", $sidebar);
        $this->assertStringNotContainsString("title: 'Canvas'", $sidebar);
        $this->assertStringNotContainsString("title: 'What should we post?'", $sidebar);
        $this->assertStringNotContainsString("title: 'Vote on features'", $sidebar);
        $this->assertStringNotContainsString("title: 'Monthly report'", $sidebar);
    }
}
