<?php

namespace Tests\Unit;

use App\Support\CaptionExcerpt;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CaptionExcerptTest extends TestCase
{
    #[Test]
    public function it_returns_short_captions_unchanged(): void
    {
        $this->assertSame('Hello world.', CaptionExcerpt::limit('Hello world.', 50));
    }

    #[Test]
    public function it_prefers_a_sentence_ending_over_mid_word_cuts(): void
    {
        $caption = 'Presentation night was a whole vibe. ANNIVERSARY party next week with friends and food.';
        $limited = CaptionExcerpt::limit($caption, 40);

        $this->assertSame('Presentation night was a whole vibe.', $limited);
    }

    #[Test]
    public function it_falls_back_to_a_word_boundary(): void
    {
        $caption = 'one two three four five six seven eight nine ten eleven twelve';
        $limited = CaptionExcerpt::limit($caption, 28);

        $this->assertNotNull($limited);
        $this->assertStringEndsWith('…', $limited);
        $this->assertStringNotContainsString('twelv', $limited);
        $this->assertMatchesRegularExpression('/\b(five|six)…$/u', $limited);
    }
}
