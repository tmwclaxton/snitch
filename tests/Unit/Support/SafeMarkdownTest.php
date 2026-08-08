<?php

namespace Tests\Unit\Support;

use App\Support\SafeMarkdown;
use PHPUnit\Framework\TestCase;

class SafeMarkdownTest extends TestCase
{
    public function test_renders_bold_italic_and_paragraphs(): void
    {
        $html = SafeMarkdown::toHtml("**1. Hook.**\nOpen on *speaker*.");

        $this->assertNotNull($html);
        $this->assertStringContainsString('<strong>1. Hook.</strong>', $html);
        $this->assertStringContainsString('<em>speaker</em>', $html);
        $this->assertStringContainsString('<p>', $html);
    }

    public function test_strips_raw_html_input(): void
    {
        $html = SafeMarkdown::toHtml('Hello <script>alert(1)</script> **world**');

        $this->assertNotNull($html);
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('<strong>world</strong>', $html);
    }

    public function test_returns_null_for_empty_input(): void
    {
        $this->assertNull(SafeMarkdown::toHtml(null));
        $this->assertNull(SafeMarkdown::toHtml(''));
        $this->assertNull(SafeMarkdown::toHtml("  \n  "));
    }

    public function test_splits_inline_numbered_steps_into_list_items(): void
    {
        $source = '1. Identify a common pain point entrepreneurs face (e.g., burnout, lack of clients). 2. State that this pain point is actually building a specific trait in them. 3. List 2-3 specific examples of this pain. 4. Conclude with your offer.';

        $html = SafeMarkdown::toHtml($source);

        $this->assertNotNull($html);
        $this->assertStringContainsString('<ol>', $html);
        $this->assertSame(4, substr_count($html, '<li>'));
        $this->assertStringContainsString('Identify a common pain point', $html);
        $this->assertStringContainsString('State that this pain point', $html);
        $this->assertStringContainsString('List 2-3 specific examples', $html);
        $this->assertStringContainsString('Conclude with your offer', $html);
        $this->assertStringNotContainsString('2. State', $html);
    }

    public function test_normalize_list_breaks_preserves_ranges_like_two_dash_three(): void
    {
        $normalized = SafeMarkdown::normalizeListBreaks(
            '1. List 2-3 examples. 2. End on the ask.',
        );

        $this->assertSame("1. List 2-3 examples.\n2. End on the ask.", $normalized);
    }

    public function test_splits_inline_paren_numbered_and_bullet_markers(): void
    {
        $this->assertSame(
            "1) Hook hard\n2) Show product\n3) CTA",
            SafeMarkdown::normalizeListBreaks('1) Hook hard 2) Show product 3) CTA'),
        );

        $this->assertSame(
            "• Open on steam\n• Cut to glaze",
            SafeMarkdown::normalizeListBreaks('• Open on steam • Cut to glaze'),
        );
    }
}
