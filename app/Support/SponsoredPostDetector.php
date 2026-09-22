<?php

namespace App\Support;

use App\Models\Post;

/**
 * Marks a post as sponsored when the scrape or analysis says so.
 * Public scrapes do not reveal organic boosts reliably.
 */
class SponsoredPostDetector
{
    public function looksSponsored(Post $post): bool
    {
        $payload = is_array($post->raw_payload) ? $post->raw_payload : [];

        if ($this->truthyPartnership($payload['paidPartnership'] ?? null)) {
            return true;
        }

        foreach (['isSponsored', 'is_sponsored', 'sponsored', 'isPaidPartnership'] as $key) {
            if ($this->truthyPartnership($payload[$key] ?? null)) {
                return true;
            }
        }

        $caption = (string) ($post->caption ?? '');

        if ($caption !== '' && preg_match('/(?:^|\s)(?:#ad\b|#sponsored\b|paid\s+partnership\b|#advert\b)/iu', $caption) === 1) {
            return true;
        }

        $topics = $post->analysis?->topics;

        if (is_array($topics)) {
            foreach ($topics as $topic) {
                if (! is_string($topic)) {
                    continue;
                }

                $normalized = mb_strtolower(trim($topic));

                if (in_array($normalized, ['paid ads', 'paid_ads', 'paid ad', 'sponsored'], true)) {
                    return true;
                }
            }
        }

        $terms = $post->analysis?->relationLoaded('terms')
            ? $post->analysis->terms
            : null;

        if ($terms !== null) {
            foreach ($terms as $term) {
                if (($term->slug ?? null) === 'paid_ads') {
                    return true;
                }
            }
        }

        return false;
    }

    private function truthyPartnership(mixed $value): bool
    {
        if ($value === true || $value === 1 || $value === '1') {
            return true;
        }

        if (is_string($value) && trim($value) !== '') {
            return true;
        }

        return is_array($value) && $value !== [];
    }
}
