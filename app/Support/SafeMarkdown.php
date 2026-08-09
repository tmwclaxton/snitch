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

        $normalized = self::coerceRemakeSteps($trimmed);

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

    /**
     * Turn unnumbered multi-step remake copy into a Markdown ordered list.
     */
    public static function coerceRemakeSteps(string $text): string
    {
        $text = self::normalizeListBreaks($text);

        if (self::hasStepMarkers($text)) {
            return $text;
        }

        $paragraphs = array_values(array_filter(
            array_map(trim(...), preg_split('/\n{2,}/', trim($text)) ?: []),
            static fn (string $part): bool => $part !== '',
        ));

        if (count($paragraphs) >= 2) {
            return self::numberSteps($paragraphs);
        }

        $lines = array_values(array_filter(
            array_map(trim(...), explode("\n", trim($text))),
            static fn (string $line): bool => $line !== '',
        ));

        if (count($lines) >= 2) {
            return self::numberSteps($lines);
        }

        if (count($lines) === 1) {
            $sentences = self::splitSentences($lines[0]);

            if (count($sentences) >= 2) {
                return self::numberSteps($sentences);
            }
        }

        return $text;
    }

    /**
     * @param  list<string>  $steps
     */
    private static function numberSteps(array $steps): string
    {
        $numbered = [];

        foreach ($steps as $index => $step) {
            $numbered[] = ($index + 1).'. '.trim($step);
        }

        return implode("\n", $numbered);
    }

    /**
     * @return list<string>
     */
    private static function splitSentences(string $text): array
    {
        $parts = preg_split('/(?<=[.!?])\s+(?=[A-Z0-9"\'(])/', trim($text)) ?: [];

        return array_values(array_filter(
            array_map(trim(...), $parts),
            static fn (string $part): bool => $part !== '',
        ));
    }

    private static function hasStepMarkers(string $text): bool
    {
        foreach (preg_split('/\n+/', trim($text)) ?: [] as $line) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            if (preg_match('/^(\*\*)?\d+[.)]\s+\S/u', $line) === 1) {
                return true;
            }

            if (preg_match('/^[-*+•·]\s+\S/u', $line) === 1) {
                return true;
            }
        }

        return false;
    }
}
