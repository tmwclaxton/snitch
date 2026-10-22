<?php

namespace Tests\Unit\Brief;

use App\Services\Brief\DailyBriefValidator;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class HookTimesUsualFalsePositiveTest extends TestCase
{
    #[Test]
    public function validator_does_not_treat_handle_token_as_caption_hook(): void
    {
        $facts = [
            'allowed_handles' => ['yellowzest', 'aprilrosemarketing'],
            'allowed_post_ids' => [10, 11],
            'brief_date' => '2026-10-22',
            'own' => [
                'handle' => 'aprilrosemarketing',
                'followers_now' => 200,
                'posts_last_7d_count' => 0,
                'days_since_last_post' => 9,
            ],
            'competitors' => [
                [
                    'handle' => 'yellowzest',
                    'posts_last_7d_count' => 5,
                    'posts_last_7d' => [
                        [
                            'post_id' => 10,
                            'handle' => 'yellowzest',
                            'format' => 'Reel',
                            'hook' => 'Yellow zest night out',
                            'caption' => 'Yellow zest night out with the crew',
                            'times_usual' => 3.2,
                            'times_usual_label' => '3.2 times their usual',
                        ],
                        [
                            'post_id' => 11,
                            'handle' => 'yellowzest',
                            'format' => 'Reel',
                            'hook' => 'Another night',
                            'caption' => 'Another night downtown',
                            'times_usual' => 1.2,
                            'times_usual_label' => '1.2 times their usual',
                        ],
                    ],
                    'best_post_7d' => [
                        'post_id' => 10,
                        'times_usual' => 3.2,
                        'format' => 'Reel',
                    ],
                ],
            ],
        ];

        $actions = [
            [
                'title' => 'Post a Reel today at 20:00.',
                'why' => 'That Reel idea is earning attention from 18 Oct at 3.2 times their usual.',
                'how' => 'Ship one Reel tonight.',
                'related_handles' => ['yellowzest'],
                'related_post_ids' => [10],
            ],
            [
                'title' => 'Comment on the 1.2x Reel.',
                'why' => 'A quieter Reel is still warm at 1.2 times their usual - leave one real comment.',
                'how' => 'Leave a useful comment.',
                'related_handles' => ['yellowzest'],
                'related_post_ids' => [11],
            ],
            [
                'title' => 'Break the quiet streak today.',
                'why' => 'You have 0 posts in 7 days while @yellowzest posted 5 times.',
                'how' => 'Post once today.',
                'related_handles' => ['yellowzest'],
                'related_post_ids' => [10],
            ],
        ];

        $result = app(DailyBriefValidator::class)->validate([
            'headline' => 'Rivals are winning with Reels - post one today at 20:00.',
            'actions' => $actions,
            'own_summary' => 'Quiet week for @aprilrosemarketing.',
            'competitor_summary' => '@yellowzest posted 5 times; strongest Reel at 3.2 times their usual.',
            'watch' => [],
        ], $facts);

        $this->assertTrue($result['ok'], implode('; ', $result['errors']));
        $this->assertFalse(collect($result['errors'])->contains(
            fn (string $error): bool => str_contains($error, 'post "Yellow"')
                || str_contains($error, 'post "yellowzest"'),
        ));
    }
}
