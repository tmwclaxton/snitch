<?php

namespace Tests\Unit\Analysis;

use App\DataTransferObjects\VideoAnalysisResult;
use App\Services\Analysis\VideoAnalysisSuccessEvaluator;
use Tests\TestCase;

class VideoAnalysisSuccessEvaluatorTest extends TestCase
{
    public function test_passes_complete_result(): void
    {
        $result = VideoAnalysisResult::fromModelPayload([
            'concept' => 'Process-to-product payoff in under 10 seconds',
            'hook' => 'Open on the pastry steam',
            'hook_window' => ['start_sec' => 0, 'end_sec' => 3],
            'visual_summary' => str_repeat('Warm bakery counter with soft daylight and matte grade. ', 2),
            'idea' => 'Curiosity gap then proof: steam tease before the finished loaf.',
            'topics' => ['process reveal', 'bakery ASMR'],
            'cta' => 'Order for Saturday',
            'how_to_copy' => 'Film the oven open, cut to glaze drip, end on pack shot.',
            'sfx' => [
                ['at_sec' => 0.4, 'label' => 'oven door', 'role' => 'accent'],
            ],
            'music_title' => 'Loaf Beat',
            'music_artist' => 'Kitchen Radio',
            'is_original_audio' => false,
        ], 'qwen3.7-flash');

        $evaluation = app(VideoAnalysisSuccessEvaluator::class)->evaluate($result);

        $this->assertTrue($evaluation['passed']);
        $this->assertSame([], $evaluation['failures']);
    }

    public function test_fails_caption_echo_and_generic_slop(): void
    {
        $caption = 'Come try our warm bakery croissant special this weekend with soft glaze and fresh butter layers for the family table.';
        $result = VideoAnalysisResult::fromModelPayload([
            'concept' => 'Come try our warm bakery croissant special this weekend with soft glaze',
            'hook' => 'Come try our warm bakery croissant special this weekend',
            'hook_window' => ['start_sec' => 0, 'end_sec' => 3],
            'visual_summary' => str_repeat('Warm bakery croissant special with soft glaze and fresh butter layers for the family table. ', 2),
            'idea' => 'Come try our warm bakery croissant special this weekend with soft glaze and fresh butter layers.',
            'cta' => 'Shop',
            'how_to_copy' => 'Post more consistently with engaging content and a relatable vibe plus great energy.',
            'sfx' => [],
            'topics' => [],
        ], 'qwen3.7-flash');

        $evaluation = app(VideoAnalysisSuccessEvaluator::class)->evaluate($result, $caption);

        $this->assertFalse($evaluation['passed']);
        $this->assertNotEmpty($evaluation['failures']);
        $this->assertContains('analysis echoes caption/script too closely', $evaluation['failures']);
        $this->assertContains('generic AI filler without named mechanic', $evaluation['failures']);
    }

    public function test_short_topic_caption_nouns_do_not_count_as_echo(): void
    {
        $caption = 'Story on how @thesamparr hustled from $0 in San Francisco with his Miracle Craigslist Roommate Finder Guide';
        $result = VideoAnalysisResult::fromModelPayload([
            'concept' => 'Founder origin story that turns a scrappy freebie into proof of hustle',
            'hook' => 'Cold open on the zero-dollar starting line before the payoff',
            'hook_window' => ['start_sec' => 0, 'end_sec' => 3],
            'visual_summary' => str_repeat('Talking-head cuts with on-screen proof docs and city b-roll. ', 2),
            'idea' => 'Status-via-scarcity: name the Craigslist roommate guide as the concrete artifact that made the San Francisco grind believable.',
            'cta' => 'No explicit CTA',
            'how_to_copy' => "1. Open on the empty-bank-account beat.\n2. Show one named freebie as proof.\n3. Land on the lesson in one line.",
            'sfx' => [],
            'topics' => ['origin story', 'proof artifact'],
        ], 'qwen3.7-flash');

        $evaluation = app(VideoAnalysisSuccessEvaluator::class)->evaluate($result, $caption);

        $this->assertTrue($evaluation['passed'], implode(', ', $evaluation['failures']));
        $this->assertNotContains('analysis echoes caption/script too closely', $evaluation['failures']);
    }

    public function test_short_hook_window_is_clamped_before_evaluate(): void
    {
        $result = VideoAnalysisResult::fromModelPayload([
            'concept' => 'Contrast cut between mess and clean pack',
            'hook' => 'Too short hook window from the model',
            'hook_window' => ['start_sec' => 0, 'end_sec' => 1.5],
            'visual_summary' => str_repeat('Visual detail here. ', 5),
            'idea' => 'An idea line naming the mechanic',
            'cta' => 'Shop now',
            'how_to_copy' => 'Remake with your brand product first.',
            'sfx' => [],
        ], 'qwen3.7-flash');

        $this->assertSame(3.0, $result->hookWindowEndSeconds);

        $evaluation = app(VideoAnalysisSuccessEvaluator::class)->evaluate($result);

        $this->assertTrue($evaluation['passed']);
        $this->assertNotContains('hook window end below 3 seconds', $evaluation['failures']);
    }

    public function test_empty_cta_is_floored_before_evaluate(): void
    {
        $result = VideoAnalysisResult::fromModelPayload([
            'concept' => 'Contrast cut between mess and clean pack',
            'hook' => 'Cold open on the before state',
            'hook_window' => ['start_sec' => 0, 'end_sec' => 3],
            'visual_summary' => str_repeat('Visual detail here. ', 5),
            'idea' => 'An idea line naming the mechanic',
            'cta' => '',
            'how_to_copy' => 'Remake with your brand product first.',
            'sfx' => [],
        ], 'qwen3.7-flash');

        $this->assertSame('No explicit CTA', $result->cta);

        $evaluation = app(VideoAnalysisSuccessEvaluator::class)->evaluate($result);

        $this->assertTrue($evaluation['passed']);
        $this->assertNotContains('cta missing', $evaluation['failures']);
    }

    public function test_fails_chinese_prose_fields(): void
    {
        $result = VideoAnalysisResult::fromModelPayload([
            'concept' => 'Leveraging the boring-business frame with proof docs',
            'hook' => 'But some of the most profitable businesses are the ones that nobody talks about.',
            'hook_window' => ['start_sec' => 0, 'end_sec' => 3],
            'visual_summary' => str_repeat('Speaker against a white wall with document grid overlays. ', 2),
            'idea' => '利用反直觉对比制造认知冲突，随后通过展示具体的案例文件提供实质性 proof。',
            'topics' => ['反直觉营销', '利基市场'],
            'cta' => 'Browse the library before you invest time.',
            'how_to_copy' => '1. 提炼一个被大众忽视但现金流稳定的业务；2. 展示带有具体价格的文档封面。',
            'sfx' => [],
        ], 'qwen3.7-flash');

        $evaluation = app(VideoAnalysisSuccessEvaluator::class)->evaluate(
            $result,
            'A longer caption with enough English words to classify language against the analysis fields.',
        );

        $this->assertFalse($evaluation['passed']);
        $this->assertContains('analysis must be English', $evaluation['failures']);
    }

    public function test_short_caption_skips_english_language_check(): void
    {
        $result = VideoAnalysisResult::fromModelPayload([
            'concept' => 'Leveraging the boring-business frame with proof docs',
            'hook' => 'But some of the most profitable businesses are the ones that nobody talks about.',
            'hook_window' => ['start_sec' => 0, 'end_sec' => 3],
            'visual_summary' => str_repeat('Speaker against a white wall with document grid overlays. ', 2),
            'idea' => '利用反直觉对比制造认知冲突，随后通过展示具体的案例文件提供实质性 proof。',
            'topics' => ['反直觉营销', '利基市场'],
            'cta' => 'Browse the library before you invest time.',
            'how_to_copy' => '1. 提炼一个被大众忽视但现金流稳定的业务；2. 展示带有具体价格的文档封面。',
            'sfx' => [],
        ], 'qwen3.7-flash');

        $evaluation = app(VideoAnalysisSuccessEvaluator::class)->evaluate(
            $result,
            'And so much more… #DoGoodGetFit',
        );

        $this->assertNotContains('analysis must be English', $evaluation['failures']);
    }

    public function test_fails_placeholder_latin_copy(): void
    {
        $result = VideoAnalysisResult::fromModelPayload([
            'concept' => 'Agency process as entertainment',
            'hook' => 'Open on the brief, land on the proof.',
            'hook_window' => ['start_sec' => 0, 'end_sec' => 3],
            'visual_summary' => 'Quia voluptas ut voluptatem a dolorum nulla impedit. Quia eos non maiores similique.',
            'idea' => 'Illo fugit aut maiores.',
            'cta' => 'Save this for the next content meeting',
            'how_to_copy' => "1. Open on the messy brief.\n2. Cut to the still that proves it.\n3. End on the ask.",
            'sfx' => [],
        ], 'qwen3.7-flash');

        $evaluation = app(VideoAnalysisSuccessEvaluator::class)->evaluate($result);

        $this->assertFalse($evaluation['passed']);
        $this->assertContains('placeholder or unprocessed copy', $evaluation['failures']);
    }

    public function test_long_recap_caption_allows_analytical_output(): void
    {
        /** @var array{158: string, 159: string} $captions */
        $captions = require base_path('tests/Fixtures/Analysis/great_friendship_recap_captions.php');

        foreach ($captions as $postId => $caption) {
            $this->assertGreaterThan(300, mb_strlen($caption), "fixture caption for post {$postId}");

            $result = VideoAnalysisResult::fromModelPayload([
                'concept' => 'Event-recap montage that sells belonging before the ask',
                'hook' => 'Cold open on crowded tables then cut to a lone arrival finding a seat',
                'hook_window' => ['start_sec' => 0, 'end_sec' => 3],
                'visual_summary' => str_repeat('Handheld cuts between game boards, volunteer hosts, and first-timer reactions under warm practical lamps. ', 2),
                'idea' => 'FOMO-plus-proof: show strangers already bonded so the ticket CTA feels like joining a room mid-conversation.',
                'cta' => 'Follow for the next Friday social',
                'how_to_copy' => "1. Open on the liveliest table beat.\n2. Insert one first-timer arrival beat.\n3. Land on the soft ticket ask with host voiceover.",
                'transcript' => 'Last Friday we got together and it was incredible to see so many new faces racing between pubs with handmade cards.',
                'sfx' => [],
                'topics' => ['community event recap', 'belonging proof'],
            ], 'qwen3.7-flash');

            $evaluation = app(VideoAnalysisSuccessEvaluator::class)->evaluate($result, $caption);

            $this->assertTrue(
                $evaluation['passed'],
                "post {$postId}: ".implode(', ', $evaluation['failures']).' / '.json_encode($evaluation['caption_echo']),
            );
            $this->assertNotContains('analysis echoes caption/script too closely', $evaluation['failures']);
            $this->assertFalse($evaluation['caption_echo']['echoed'] ?? true);
        }
    }

    public function test_long_recap_caption_rejects_paraphrased_caption_dump(): void
    {
        /** @var array{158: string, 159: string} $captions */
        $captions = require base_path('tests/Fixtures/Analysis/great_friendship_recap_captions.php');

        foreach ($captions as $postId => $caption) {
            // Paraphrase that still reuses the caption's content vocabulary heavily.
            $echo = preg_replace('/\s+/', ' ', $caption) ?? $caption;
            $result = VideoAnalysisResult::fromModelPayload([
                'concept' => $echo,
                'hook' => mb_substr($echo, 0, 180),
                'hook_window' => ['start_sec' => 0, 'end_sec' => 3],
                'visual_summary' => $echo.' Warm lamps over packed tables and handmade scorecards fill every cut.',
                'idea' => $echo,
                'cta' => 'Follow for the next meetup drop',
                'how_to_copy' => '1. '.$echo."\n2. Keep thanking volunteers and venues.\n3. End on the ticket ask.",
                'transcript' => '',
                'sfx' => [],
                'topics' => ['pub race', 'board games', 'london meetup'],
            ], 'qwen3.7-flash');

            $evaluation = app(VideoAnalysisSuccessEvaluator::class)->evaluate($result, $caption);

            $this->assertFalse($evaluation['passed'], "post {$postId} paraphrase should fail");
            $this->assertContains('analysis echoes caption/script too closely', $evaluation['failures']);
            $this->assertTrue($evaluation['caption_echo']['echoed'] ?? false);
            $this->assertNotNull($evaluation['caption_echo']['score'] ?? null);
            $this->assertNotNull($evaluation['caption_echo']['reason'] ?? null);
        }
    }

    public function test_transcript_and_quotes_do_not_count_toward_caption_echo(): void
    {
        $caption = str_repeat('Last Friday we got together for games and new mates across the city with volunteers keeping score. ', 4);
        $this->assertGreaterThan(300, mb_strlen($caption));

        $result = VideoAnalysisResult::fromModelPayload([
            'concept' => 'Proof-of-belonging recap that turns strangers into a ticket CTA',
            'hook' => 'Open on the empty chair, cut to the full table',
            'hook_window' => ['start_sec' => 0, 'end_sec' => 3],
            'visual_summary' => str_repeat('Quick cuts of boards, snacks, and host intros under practical lamps. ', 2),
            'idea' => 'Social proof montage: stack micro-bonds so the ask feels low-risk.',
            'cta' => 'Book the next social',
            'how_to_copy' => "1. Film three micro-bond moments.\n2. Keep host voiceover craft-focused.\n3. End on the soft ask.",
            // Deliberately echo the caption inside transcript + a quoted hook line - must not fail.
            'transcript' => $caption,
            'sfx' => [],
            'topics' => ['community montage'],
        ], 'qwen3.7-flash');

        // Inject a quote of caption phrasing into hook without making the craft fields a dump.
        $result = VideoAnalysisResult::fromModelPayload([
            'concept' => $result->concept,
            'hook' => 'Scroll stop on "Last Friday we got together" then cut to the empty-chair beat',
            'hook_window' => ['start_sec' => 0, 'end_sec' => 3],
            'visual_summary' => $result->visualSummary,
            'idea' => $result->idea,
            'cta' => $result->cta,
            'how_to_copy' => $result->howToCopy,
            'transcript' => $caption,
            'sfx' => [],
            'topics' => $result->topics,
        ], 'qwen3.7-flash');

        $evaluation = app(VideoAnalysisSuccessEvaluator::class)->evaluate($result, $caption);

        $this->assertTrue($evaluation['passed'], implode(', ', $evaluation['failures']).' / '.json_encode($evaluation['caption_echo']));
        $this->assertNotContains('analysis echoes caption/script too closely', $evaluation['failures']);
    }
}
