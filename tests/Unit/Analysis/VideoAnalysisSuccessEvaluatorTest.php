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
            'concept' => 'Come try our warm bakery croissant special this weekend',
            'hook' => 'Come try our warm bakery croissant special',
            'hook_window' => ['start_sec' => 0, 'end_sec' => 3],
            'visual_summary' => str_repeat('Warm bakery croissant special with soft glaze and fresh butter. ', 2),
            'idea' => 'Engaging content with a relatable vibe and great energy for viewers.',
            'cta' => 'Shop',
            'how_to_copy' => 'Post more consistently with engaging content and great energy.',
            'sfx' => [],
            'topics' => [],
        ], 'qwen3.7-flash');

        $evaluation = app(VideoAnalysisSuccessEvaluator::class)->evaluate($result, $caption);

        $this->assertFalse($evaluation['passed']);
        $this->assertNotEmpty($evaluation['failures']);
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

        $evaluation = app(VideoAnalysisSuccessEvaluator::class)->evaluate($result);

        $this->assertFalse($evaluation['passed']);
        $this->assertContains('analysis must be English', $evaluation['failures']);
    }
}
