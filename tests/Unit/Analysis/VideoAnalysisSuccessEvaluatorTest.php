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
            'hook' => 'Open on the pastry steam',
            'hook_window' => ['start_sec' => 0, 'end_sec' => 3],
            'visual_summary' => str_repeat('Warm bakery counter with soft daylight and matte grade. ', 2),
            'idea' => 'Show process then product',
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

    public function test_fails_short_hook_window(): void
    {
        $result = VideoAnalysisResult::fromModelPayload([
            'hook' => 'Too short hook',
            'hook_window' => ['start_sec' => 0, 'end_sec' => 1.5],
            'visual_summary' => str_repeat('Visual detail here. ', 5),
            'idea' => 'An idea line',
            'cta' => 'Shop now',
            'how_to_copy' => 'Remake with your brand product first.',
            'sfx' => [],
        ], 'qwen3.7-flash');

        $evaluation = app(VideoAnalysisSuccessEvaluator::class)->evaluate($result);

        $this->assertFalse($evaluation['passed']);
        $this->assertContains('hook window end below 3 seconds', $evaluation['failures']);
    }
}
