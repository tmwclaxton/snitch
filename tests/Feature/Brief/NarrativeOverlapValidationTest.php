<?php

namespace Tests\Feature\Brief;

use App\Services\Brief\DailyBriefValidator;
use Tests\TestCase;

class NarrativeOverlapValidationTest extends TestCase
{
    public function test_competitor_summary_cannot_repeat_narrative_phrases(): void
    {
        $validator = app(DailyBriefValidator::class);
        $facts = [
            'allowed_handles' => ['ownclub', 'onehousesocialclub', 'goodgym'],
            'allowed_post_ids' => [],
            'own' => [
                'handle' => 'ownclub',
                'followers_now' => 100,
                'posts_last_7d_count' => 0,
                'days_since_last_post' => 21,
            ],
            'competitors' => [],
            'format_mix' => ['competitor_posts_7d' => 5],
        ];

        $narrative = implode(' ', [
            'You have been quiet for three weeks, so the first job is a post, not a rebrand.',
            'One House said the awkward part out loud on a Reel about leaving a crowded room without a real conversation.',
            'That Reel reached 3.2 times their usual.',
            'Their mansion takeover Carousel reached 2.1 times their usual because it named a specific room.',
            'Film a weekday evening Reel around who someone will actually talk to, then leave a genuine comment on the mansion Carousel.',
            'Skip the Halloween quiz because a costume theme is the wrong model for this comeback.',
        ]);

        $overlap = implode(' ', [
            'One House said the awkward part out loud on a Reel about leaving a crowded room without a real conversation.',
            'Their mansion takeover Carousel reached 2.1 times their usual because it named a specific room.',
            'Skip the Halloween quiz because a costume theme is the wrong model for this comeback.',
        ]);

        $actions = [
            ['title' => 'Post a Reel tonight.', 'why' => 'Break the quiet streak.', 'how' => 'Ship one.', 'related_handles' => [], 'related_post_ids' => []],
            ['title' => 'Comment on a rival hit.', 'why' => 'Show up in the thread.', 'how' => 'Leave one.', 'related_handles' => [], 'related_post_ids' => []],
            ['title' => 'Test a Carousel this week.', 'why' => 'Try a room-by-room layout.', 'how' => 'Draft slides.', 'related_handles' => [], 'related_post_ids' => []],
        ];

        $result = $validator->validate([
            'headline' => 'Quiet week while rivals keep posting.',
            'narrative' => $narrative,
            'own_summary' => '',
            'competitor_summary' => $overlap,
            'actions' => $actions,
            'watch' => [],
        ], $facts);

        $this->assertFalse($result['ok']);
        $blob = implode(' ', $result['errors']);
        $this->assertTrue(
            str_contains($blob, 'repeats the narrative') || str_contains($blob, 'reuses narrative phrases'),
            $blob,
        );
    }

    public function test_positive_action_titles_reject_skip_and_do_not(): void
    {
        $validator = app(DailyBriefValidator::class);
        $facts = [
            'allowed_handles' => ['ownclub'],
            'allowed_post_ids' => [],
            'own' => [
                'handle' => 'ownclub',
                'followers_now' => 100,
                'posts_last_7d_count' => 0,
                'days_since_last_post' => 5,
            ],
            'competitors' => [],
            'format_mix' => ['competitor_posts_7d' => 0],
        ];

        $result = $validator->validate([
            'headline' => 'Quiet week for @ownclub.',
            'narrative' => implode(' ', array_fill(0, 90, 'word')),
            'own_summary' => '',
            'competitor_summary' => '',
            'actions' => [
                ['title' => 'Post a Reel today.', 'why' => 'Break silence.', 'how' => 'Ship.', 'related_handles' => [], 'related_post_ids' => []],
                ['title' => 'Comment on a rival.', 'why' => 'Show up.', 'how' => 'Leave one.', 'related_handles' => [], 'related_post_ids' => []],
                ['title' => 'Do not copy the quiz Reel.', 'why' => 'It underperformed.', 'how' => 'Ignore.', 'related_handles' => [], 'related_post_ids' => []],
            ],
            'watch' => [],
        ], $facts);

        $this->assertFalse($result['ok']);
        $this->assertTrue(collect($result['errors'])->contains(
            fn (string $error): bool => str_contains($error, 'must be a positive action'),
        ));
    }
}
