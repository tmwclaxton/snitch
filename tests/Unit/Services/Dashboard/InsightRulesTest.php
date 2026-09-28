<?php

namespace Tests\Unit\Services\Dashboard;

use App\Services\Dashboard\InsightRules;
use Tests\TestCase;

class InsightRulesTest extends TestCase
{
    public function test_no_insight_renders_without_its_threshold(): void
    {
        $rules = new InsightRules;

        $items = $rules->top([
            'own' => [
                'posts_n' => 2,
                'posts_per_week' => 0.25,
                'er' => 1.0,
                'format_share' => ['Reel' => 10],
            ],
            'rival_rows' => [[
                'handle' => 'peer',
                'posts_n' => 2,
                'posts_per_week' => 4,
                'er' => 5.0,
                'format_share' => ['Reel' => 80],
                'growth_pct' => 10,
                'winners' => 2,
            ]],
            'peer_posts_per_week' => 4,
            'peer_er' => 2,
            'rival_posts_n' => 2,
            'top_winner' => ['handle' => 'peer', 'pi' => 4.0, 'prior_n' => 2, 'format' => 'Reel', 'hook' => 'Hi', 'when' => 'Sun'],
            'best_heatmap_cell' => ['pi' => 2.0, 'n' => 2, 'day' => 'Sun', 'block' => '20-24'],
        ]);

        $this->assertSame([], $items);
    }

    public function test_frequency_insight_fires_when_thresholds_met(): void
    {
        $rules = new InsightRules;

        $items = $rules->top([
            'own' => [
                'posts_n' => 6,
                'posts_per_week' => 0.5,
                'er' => 2.0,
                'format_share' => ['Reel' => 20],
            ],
            'rival_rows' => [],
            'peer_posts_per_week' => 3.5,
            'peer_er' => 2.0,
            'rival_posts_n' => 20,
        ]);

        $this->assertNotEmpty($items);
        $this->assertSame('frequency', $items[0]['category']);
        $this->assertStringContainsString('3.5', $items[0]['text']);
    }

    public function test_top_returns_at_most_one_per_category(): void
    {
        $rules = new InsightRules;

        $items = $rules->top([
            'own' => [
                'posts_n' => 20,
                'posts_per_week' => 0.5,
                'er' => 1.0,
                'format_share' => ['Reel' => 10, 'Carousel' => 10],
            ],
            'rival_rows' => [
                [
                    'handle' => 'a',
                    'posts_n' => 20,
                    'posts_per_week' => 2,
                    'er' => 3.0,
                    'format_share' => ['Reel' => 60, 'Carousel' => 30],
                    'growth_pct' => 8,
                    'winners' => 3,
                ],
                [
                    'handle' => 'b',
                    'posts_n' => 20,
                    'posts_per_week' => 1,
                    'er' => 4.0,
                    'format_share' => ['Reel' => 70, 'Carousel' => 20],
                    'growth_pct' => 12,
                    'winners' => 4,
                ],
            ],
            'peer_posts_per_week' => 2,
            'peer_er' => 2,
            'peer_growth_pct' => 3,
            'rival_posts_n' => 40,
            'peer_format_lift' => [
                'Carousel' => ['lift' => 1.6, 'n' => 12],
                'Reel' => ['lift' => 0.9, 'n' => 12],
            ],
            'top_winner' => [
                'handle' => 'a',
                'pi' => 4.2,
                'prior_n' => 20,
                'format' => 'Carousel',
                'hook' => 'Dinner recap',
                'when' => 'Sunday evening',
            ],
        ]);

        $categories = array_column($items, 'category');
        $this->assertSame($categories, array_values(array_unique($categories)));
        $this->assertLessThanOrEqual(6, count($items));
    }
}
