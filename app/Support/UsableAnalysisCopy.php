<?php

namespace App\Support;

use App\Models\PostAnalysis;

class UsableAnalysisCopy
{
    /**
     * Distinctive Faker / Lorem Latin tokens. One hit means the field was not processed.
     *
     * @var list<string>
     */
    private const PLACEHOLDER_TOKENS = [
        'lorem',
        'ipsum',
        'dolor',
        'consectetur',
        'adipiscing',
        'adipisci',
        'voluptas',
        'voluptatem',
        'dolorum',
        'quibusdam',
        'similique',
        'accusamus',
        'repellendus',
        'impedit',
        'inventore',
        'eligendi',
        'maiores',
        'fugit',
        'quidem',
        'ullam',
    ];

    public static function displayValue(?string $text): ?string
    {
        $text = trim((string) $text);

        if ($text === '' || self::looksLikePlaceholder($text)) {
            return null;
        }

        return $text;
    }

    public static function looksLikePlaceholder(string $text): bool
    {
        $tokens = preg_split('/[^a-z]+/', strtolower($text)) ?: [];

        foreach ($tokens as $token) {
            if (in_array($token, self::PLACEHOLDER_TOKENS, true)) {
                return true;
            }
        }

        return false;
    }

    public static function applyToAnalysis(PostAnalysis $analysis): void
    {
        foreach (['hook', 'idea', 'concept', 'visual_summary', 'format_notes', 'cta', 'how_to_copy'] as $field) {
            $analysis->setAttribute($field, self::displayValue($analysis->getAttribute($field)));
        }

        $music = $analysis->music;

        if (! is_array($music)) {
            return;
        }

        $title = self::displayValue(isset($music['title']) ? (string) $music['title'] : null);
        $source = isset($music['source']) ? (string) $music['source'] : '';

        if ($title === null && $source !== 'platform') {
            $analysis->setAttribute('music', null);

            return;
        }

        $music['title'] = $title;
        $music['artist'] = self::displayValue(isset($music['artist']) ? (string) $music['artist'] : null);
        $analysis->setAttribute('music', $music);
    }
}
