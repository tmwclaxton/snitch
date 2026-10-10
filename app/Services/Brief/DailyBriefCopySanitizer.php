<?php

namespace App\Services\Brief;

use App\Models\DailyBrief;

class DailyBriefCopySanitizer
{
    public function __construct(
        private DailyBriefValidator $validator,
        private BorrowedCompetitorNameSanitizer $borrowedNames,
    ) {}

    /**
     * Re-run deterministic copy rewrites on a stored brief. Returns true when the row changed.
     */
    public function sanitizeStored(DailyBrief $brief): bool
    {
        $facts = is_array($brief->facts) ? $brief->facts : [];
        $payload = is_array($brief->payload) ? $brief->payload : [];
        $headline = (string) ($brief->headline ?? '');

        $sanitized = $this->sanitizePayload($payload, $facts, $headline);

        if ($sanitized['payload'] === $payload && $sanitized['headline'] === $headline) {
            return false;
        }

        $brief->fill([
            'headline' => $sanitized['headline'],
            'payload' => $sanitized['payload'],
        ]);
        $brief->save();

        return true;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $facts
     * @return array{headline: string, payload: array<string, mixed>}
     */
    public function sanitizePayload(array $payload, array $facts, ?string $headline = null): array
    {
        $headline ??= (string) ($payload['headline'] ?? '');

        $llmShape = [
            'headline' => $headline,
            'own_summary' => (string) ($payload['own_summary'] ?? ''),
            'competitor_summary' => (string) ($payload['competitor_summary'] ?? ''),
            'watch' => is_array($payload['watch'] ?? null) ? $payload['watch'] : [],
            'actions' => is_array($payload['actions'] ?? null) ? $payload['actions'] : [],
        ];

        $llmShape = $this->validator->rewriteViewsVsUsualWording($llmShape);
        $llmShape = $this->validator->rewriteInternalPostIds($llmShape, $facts);
        $llmShape = $this->validator->rewriteBorrowedCompetitorNames($llmShape, $facts);
        $llmShape = $this->validator->rewriteUnsupportedStandoutClaims($llmShape, $facts);
        $llmShape = $this->validator->rewriteUnsupportedWeekBestClaims($llmShape, $facts);

        $payload['headline'] = (string) ($llmShape['headline'] ?? $headline);
        $payload['own_summary'] = (string) ($llmShape['own_summary'] ?? '');
        $payload['competitor_summary'] = (string) ($llmShape['competitor_summary'] ?? '');
        $payload['watch'] = $llmShape['watch'] ?? [];
        $payload['actions'] = $llmShape['actions'] ?? [];
        $payload['unused_weekly_ideas'] = $this->sanitizeUnusedWeeklyIdeas(
            is_array($payload['unused_weekly_ideas'] ?? null) ? $payload['unused_weekly_ideas'] : [],
            $facts,
        );

        return [
            'headline' => $payload['headline'],
            'payload' => $payload,
        ];
    }

    /**
     * @param  list<mixed>  $ideas
     * @param  array<string, mixed>  $facts
     * @return list<mixed>
     */
    public function sanitizeUnusedWeeklyIdeas(array $ideas, array $facts): array
    {
        $names = $this->validator->borrowedCompetitorNames($facts);

        if ($names === []) {
            return $ideas;
        }

        return array_map(function (mixed $idea) use ($names): mixed {
            if (! is_array($idea)) {
                return $idea;
            }

            foreach (['hook', 'caption_angle', 'visual', 'cta', 'why'] as $field) {
                if (isset($idea[$field]) && is_string($idea[$field])) {
                    $idea[$field] = $this->borrowedNames->rewriteText($idea[$field], $names);
                }
            }

            return $idea;
        }, $ideas);
    }
}
