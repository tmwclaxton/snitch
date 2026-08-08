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
}
