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

        $html = trim(Str::markdown($trimmed, [
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
        ]));

        return $html === '' ? null : $html;
    }
}
