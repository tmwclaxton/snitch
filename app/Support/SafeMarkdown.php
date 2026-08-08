<?php

namespace App\Support;

use Illuminate\Support\Str;

class SafeMarkdown
{
    /**
     * Convert Markdown to safe HTML for Inertia display (strip raw HTML input).
     */
    public static function toHtml(?string $markdown): ?string
    {
        if ($markdown === null) {
            return null;
        }

        $trimmed = trim($markdown);

        if ($trimmed === '') {
            return null;
        }

        $normalized = self::normalizeListBreaks($trimmed);

        $html = trim(Str::markdown($normalized, [
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
        ]));

        return $html === '' ? null : $html;
    }

    /**
     * Models often return "1. Step. 2. Step. 3. Step." on one line.
     * CommonMark then treats it as a single list item and drops the leading "1.".
     * Split those inline markers onto their own lines before markdown render.
     */
    public static function normalizeListBreaks(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);

        // Numbered markers: "2. Next" / "2) Next" after non-newline content.
        $text = preg_replace('/(?<=\S)[ \t]+(?=\d{1,2}[.)]\s+\S)/u', "\n", $text) ?? $text;

        // Bullet markers (dot / middle-dot) mid-line.
        $text = preg_replace('/(?<=\S)[ \t]+(?=[•·]\s+\S)/u', "\n", $text) ?? $text;

        // Hyphen / asterisk bullets only after sentence punctuation (avoid "well - known").
        $text = preg_replace('/(?<=[.;:!?\]])[ \t]+(?=[-*+]\s+\S)/u', "\n", $text) ?? $text;

        return $text;
    }
}
