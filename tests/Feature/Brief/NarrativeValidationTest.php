<?php

namespace Tests\Feature\Brief;

use App\Services\Brief\DailyBriefValidator;
use Tests\TestCase;

class NarrativeValidationTest extends TestCase
{
    public function test_narrative_word_count_must_be_eighty_to_one_forty_when_present(): void
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

        $actions = [
            ['title' => 'Post today.', 'why' => 'Quiet week.', 'how' => 'Ship one.', 'related_handles' => [], 'related_post_ids' => []],
            ['title' => 'Comment today.', 'why' => 'Show up.', 'how' => 'Leave one.', 'related_handles' => [], 'related_post_ids' => []],
            ['title' => 'Skip weak posts.', 'why' => 'Stay sharp.', 'how' => 'Ignore fluff.', 'related_handles' => [], 'related_post_ids' => []],
        ];

        $short = $validator->validate([
            'headline' => 'Quiet week for @ownclub.',
            'narrative' => 'Too short to be a memo paragraph.',
            'own_summary' => '',
            'competitor_summary' => '',
            'actions' => $actions,
            'watch' => [],
        ], $facts);

        $this->assertFalse($short['ok']);
        $this->assertTrue(collect($short['errors'])->contains(
            fn (string $error): bool => str_starts_with($error, 'narrative must be 80 to 140 words'),
        ));

        $okNarrative = implode(' ', array_fill(0, 90, 'word'));
        $ok = $validator->validate([
            'headline' => 'Quiet week for @ownclub.',
            'narrative' => $okNarrative,
            'own_summary' => '',
            'competitor_summary' => '',
            'actions' => $actions,
            'watch' => [],
        ], $facts);

        $this->assertTrue($ok['ok'], implode('; ', $ok['errors']));
        $this->assertSame($okNarrative, $ok['output']['narrative']);
    }

    public function test_narrative_rejects_stat_inventory_tone(): void
    {
        $validator = app(DailyBriefValidator::class);
        $facts = [
            'allowed_handles' => ['ownclub', 'rivala', 'rivalb', 'rivalc'],
            'allowed_post_ids' => [],
            'own' => [
                'handle' => 'ownclub',
                'followers_now' => 104,
                'posts_last_7d_count' => 0,
                'days_since_last_post' => 21,
            ],
            'competitors' => [
                ['handle' => 'rivala', 'posts_last_7d_count' => 4],
                ['handle' => 'rivalb', 'posts_last_7d_count' => 3],
                ['handle' => 'rivalc', 'posts_last_7d_count' => 2],
            ],
            'format_mix' => ['competitor_posts_7d' => 9],
        ];

        $actions = [
            ['title' => 'Post today.', 'why' => 'Quiet week.', 'how' => 'Ship one.', 'related_handles' => [], 'related_post_ids' => []],
            ['title' => 'Comment today.', 'why' => 'Show up.', 'how' => 'Leave one.', 'related_handles' => [], 'related_post_ids' => []],
            ['title' => 'Skip weak posts.', 'why' => 'Stay sharp.', 'how' => 'Ignore fluff.', 'related_handles' => [], 'related_post_ids' => []],
        ];

        $dump = '@ownclub has 0 posts in 21 days. @rivala posted 4 times. @rivalb posted 3 times. @rivalc posted 2 times. Followers sit at 104 with little change while rivals keep shipping.';
        // Pad to clear the word-count floor without fixing the inventory tone.
        $dump .= ' '.implode(' ', array_fill(0, 40, 'padding'));

        $result = $validator->validate([
            'headline' => 'Quiet week for @ownclub.',
            'narrative' => $dump,
            'own_summary' => '',
            'competitor_summary' => '',
            'actions' => $actions,
            'watch' => [],
        ], $facts);

        $this->assertFalse($result['ok']);
        $blob = implode(' ', $result['errors']);
        $this->assertTrue(
            str_contains($blob, 'too many numbers') || str_contains($blob, 'starting with a handle'),
            $blob,
        );
    }

    public function test_empty_narrative_skips_word_count_for_deterministic_briefs(): void
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
            'narrative' => '',
            'own_summary' => '@ownclub is quiet.',
            'competitor_summary' => '',
            'actions' => [
                ['title' => 'Post today.', 'why' => 'Quiet week.', 'how' => 'Ship one.', 'related_handles' => [], 'related_post_ids' => []],
                ['title' => 'Comment today.', 'why' => 'Show up.', 'how' => 'Leave one.', 'related_handles' => [], 'related_post_ids' => []],
                ['title' => 'Skip weak posts.', 'why' => 'Stay sharp.', 'how' => 'Ignore fluff.', 'related_handles' => [], 'related_post_ids' => []],
            ],
            'watch' => [],
        ], $facts);

        $this->assertTrue($result['ok'], implode('; ', $result['errors']));
        $this->assertSame('', $result['output']['narrative']);
    }
}
