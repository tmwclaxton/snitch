<?php

namespace Tests\Feature;

use App\Services\Dashboard\ExecutiveBriefBuilder;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ExecutiveDashboardTest extends TestCase
{
    #[Test]
    public function builder_writes_plain_answer_headlines(): void
    {
        $brief = app(ExecutiveBriefBuilder::class)->build(
            ownRow: [
                'posts_n' => 12,
                'format_lift' => ['Carousel' => 1.8, 'Reel' => 0.9],
            ],
            kpis: [
                'data' => [
                    'cards' => [
                        [
                            'key' => 'er',
                            'you' => 12.9,
                            'peer_median' => 0.7,
                        ],
                        [
                            'key' => 'posts_per_week',
                            'you' => 1.3,
                            'peer_median' => 2.1,
                        ],
                    ],
                ],
            ],
            insights: [
                'data' => [
                    'items' => [
                        [
                            'text' => '**@club** best post got **11.8×** their usual engagement.',
                            'score' => 3.2,
                            'n' => 10,
                        ],
                    ],
                ],
            ],
            formatLift: [
                'data' => [
                    'peer_median_lift' => [
                        'Carousel' => 1.8,
                        'Reel' => 0.8,
                    ],
                ],
            ],
            activity: [
                'by_time_of_day' => [
                    ['label' => 'Mon 20:00', 'count' => 9],
                ],
            ],
            bestTimes: [
                ['label' => 'Mon 20:00', 'score' => 7.0],
            ],
            adsPanel: [
                'running_ads' => 0,
                'recommendation' => 'None of your rivals advertise, so organic is enough for now.',
            ],
        );

        $this->assertSame(
            'Post carousels on Monday at 8pm: they get 1.8× the usual.',
            $brief['what_to_post']['headline'],
        );
        $this->assertStringContainsString('engagement', strtolower($brief['performance']['headline']));
        $this->assertStringContainsString('posting less', strtolower($brief['performance']['headline']));
        $this->assertSame('None of your rivals run ads.', $brief['ads']['headline']);
        $this->assertSame('11.8×', $brief['what_to_post']['takeaways'][0]['metric']);
        $this->assertSame('@club best post beat their usual engagement.', $brief['what_to_post']['takeaways'][0]['text']);
        $this->assertLessThanOrEqual(72, mb_strlen($brief['what_to_post']['takeaways'][0]['text']));
        $this->assertStringNotContainsString('n=', $brief['what_to_post']['headline']);
        $this->assertStringNotContainsString('Peer', $brief['performance']['headline']);
    }

    #[Test]
    public function builder_writes_posting_counts_without_multiples_or_dashes(): void
    {
        $brief = app(ExecutiveBriefBuilder::class)->build(
            ownRow: ['posts_n' => 4],
            kpis: [
                'data' => [
                    'cards' => [
                        [
                            'key' => 'er',
                            'you' => null,
                            'peer_median' => 0.7,
                        ],
                        [
                            'key' => 'posts_per_week',
                            'you' => 1.0,
                            'peer_median' => 3.0,
                        ],
                    ],
                ],
            ],
            insights: [
                'data' => [
                    'items' => [
                        [
                            'text' => 'You posted **4 times in the last 4 weeks**; your competitors post a median of **3 a week**.',
                            'score' => 2.0,
                            'n' => 20,
                            'metric' => '3',
                        ],
                    ],
                ],
            ],
            formatLift: ['data' => ['peer_median_lift' => []]],
            activity: null,
            bestTimes: null,
            adsPanel: ['running_ads' => 0, 'recommendation' => ''],
        );

        $this->assertSame(
            'You post 1 a week; rivals post 3. Room to publish more.',
            $brief['performance']['headline'],
        );
        $this->assertStringNotContainsString('×', $brief['performance']['headline']);
        $this->assertStringNotContainsString(' - ', $brief['performance']['headline']);
        $this->assertSame('3', $brief['what_to_post']['takeaways'][0]['metric']);
        $this->assertSame(
            'You posted 4 times in the last 4 weeks; your competitors post a median of 3 a week.',
            $brief['what_to_post']['takeaways'][0]['text'],
        );
    }

    #[Test]
    public function builder_hides_takeaways_with_blank_interpolated_values(): void
    {
        $brief = app(ExecutiveBriefBuilder::class)->build(
            ownRow: null,
            kpis: ['data' => ['cards' => []]],
            insights: [
                'data' => [
                    'items' => [
                        [
                            'text' => 'Across your competitors, **Reels do . carousels do .**.',
                            'score' => 0.9,
                            'n' => 20,
                        ],
                        [
                            'text' => 'Carousels beat reels across your competitors (**1.8×** vs **0.9×**).',
                            'score' => 1.8,
                            'n' => 20,
                        ],
                    ],
                ],
            ],
            formatLift: ['data' => ['peer_median_lift' => []]],
            activity: null,
            bestTimes: null,
            adsPanel: ['running_ads' => 0, 'recommendation' => ''],
        );

        $this->assertCount(1, $brief['what_to_post']['takeaways']);
        $this->assertSame('1.8×', $brief['what_to_post']['takeaways'][0]['metric']);
        $this->assertSame(
            'Carousels beat reels across your competitors (1.8× vs 0.9×).',
            $brief['what_to_post']['takeaways'][0]['text'],
        );
        $this->assertStringNotContainsString('do .', $brief['what_to_post']['takeaways'][0]['text']);
    }

    #[Test]
    public function dashboard_vue_is_executive_not_text_dump(): void
    {
        $dashboard = file_get_contents(resource_path('js/pages/Dashboard.vue'));
        $this->assertIsString($dashboard);

        $this->assertStringContainsString('ExecStatTile', $dashboard);
        $this->assertStringContainsString('ExecRankBars', $dashboard);
        $this->assertStringContainsString('ExecWinnerThumb', $dashboard);
        $this->assertStringContainsString('ExecWhatWorks', $dashboard);

        $winnerThumb = file_get_contents(resource_path('js/components/dashboard/ExecWinnerThumb.vue'));
        $this->assertIsString($winnerThumb);
        $this->assertDoesNotMatchRegularExpression('/\\btruncate\\b/', $winnerThumb);
        $this->assertStringNotContainsString('whitespace-nowrap', $winnerThumb);
        $this->assertStringContainsString('\\u200b', $winnerThumb);
        $this->assertStringContainsString('text-[13px]', $winnerThumb);
        $this->assertStringContainsString('whatToPostHeadline', $dashboard);
        $this->assertStringContainsString('performanceHeadline', $dashboard);
        $this->assertStringContainsString('executive?.what_to_post?.headline', $dashboard);
        $this->assertStringContainsString('id="what-to-post"', $dashboard);
        $this->assertStringContainsString('id="performance"', $dashboard);
        $this->assertStringContainsString('id="ads"', $dashboard);
        $this->assertStringContainsString('id="vote"', $dashboard);

        $this->assertStringNotContainsString('Post ${idea.format.toLowerCase()}s around', $dashboard);
        $this->assertStringNotContainsString('CaptionPanels', $dashboard);
        $this->assertStringNotContainsString('DataNotes', $dashboard);
        $this->assertStringNotContainsString('ThemeMatrix', $dashboard);
        $this->assertStringNotContainsString('FollowerHistoryChart', $dashboard);
        $this->assertStringNotContainsString('InsightList', $dashboard);
        $this->assertStringNotContainsString('CompareTable', $dashboard);
    }
}
