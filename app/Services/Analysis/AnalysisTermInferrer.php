<?php

namespace App\Services\Analysis;

use App\DataTransferObjects\VideoAnalysisResult;
use App\Enums\AnalysisTermDimension;
use App\Models\AnalysisTerm;
use App\Models\PostAnalysis;

class AnalysisTermInferrer
{
    /**
     * Soft caps so freeform text does not spray the whole catalogue onto one post.
     */
    private const LIMITS = [
        'hook_type' => 2,
        'topic' => 4,
        'visual_craft' => 3,
    ];

    public function __construct(
        private AnalysisTermCatalogue $catalogue,
    ) {}

    /**
     * @return list<int>
     */
    public function inferIdsFromResult(VideoAnalysisResult $result): array
    {
        return $this->inferIds([
            'hook' => $result->hook,
            'concept' => $result->concept,
            'idea' => $result->idea,
            'visual_summary' => $result->visualSummary,
            'topics' => $result->topics,
            'custom_tags' => $result->customTags,
        ]);
    }

    /**
     * @return list<int>
     */
    public function inferIdsFromAnalysis(PostAnalysis $analysis): array
    {
        return $this->inferIds([
            'hook' => $analysis->hook,
            'concept' => $analysis->concept,
            'idea' => $analysis->idea,
            'visual_summary' => $analysis->visual_summary,
            'topics' => is_array($analysis->topics) ? $analysis->topics : [],
            'custom_tags' => is_array($analysis->custom_tags) ? $analysis->custom_tags : [],
        ]);
    }

    /**
     * @param  array{
     *     hook?: string|null,
     *     concept?: string|null,
     *     idea?: string|null,
     *     visual_summary?: string|null,
     *     topics?: list<string>|null,
     *     custom_tags?: list<string>|null,
     * }  $fields
     * @return list<int>
     */
    public function inferIds(array $fields): array
    {
        $slugsByDimension = $this->inferSlugs($fields);

        return array_values(array_unique(array_merge(
            $this->catalogue->resolveIds(AnalysisTermDimension::HookType, $slugsByDimension['hook_type']),
            $this->catalogue->resolveIds(AnalysisTermDimension::Topic, $slugsByDimension['topic']),
            $this->catalogue->resolveIds(AnalysisTermDimension::VisualCraft, $slugsByDimension['visual_craft']),
        )));
    }

    /**
     * @param  array{
     *     hook?: string|null,
     *     concept?: string|null,
     *     idea?: string|null,
     *     visual_summary?: string|null,
     *     topics?: list<string>|null,
     *     custom_tags?: list<string>|null,
     * }  $fields
     * @return array{hook_type: list<string>, topic: list<string>, visual_craft: list<string>}
     */
    public function inferSlugs(array $fields): array
    {
        $haystacks = [
            'hook_type' => $this->normalizeHaystack([
                $fields['hook'] ?? null,
                $fields['concept'] ?? null,
                $fields['idea'] ?? null,
                ...($fields['topics'] ?? []),
                ...($fields['custom_tags'] ?? []),
            ]),
            'topic' => $this->normalizeHaystack([
                $fields['concept'] ?? null,
                $fields['idea'] ?? null,
                ...($fields['topics'] ?? []),
                ...($fields['custom_tags'] ?? []),
            ]),
            'visual_craft' => $this->normalizeHaystack([
                $fields['visual_summary'] ?? null,
                $fields['concept'] ?? null,
                ...($fields['topics'] ?? []),
            ]),
        ];

        $matched = [
            'hook_type' => [],
            'topic' => [],
            'visual_craft' => [],
        ];

        foreach ($this->catalogue->definitions() as $row) {
            $dimension = $row['dimension'];
            $haystack = $haystacks[$dimension] ?? '';

            if ($haystack === '') {
                continue;
            }

            $score = $this->matchScore($haystack, $row['slug'], $row['label']);

            if ($score <= 0) {
                continue;
            }

            $matched[$dimension][] = [
                'slug' => $row['slug'],
                'score' => $score,
            ];
        }

        $slugs = [
            'hook_type' => [],
            'topic' => [],
            'visual_craft' => [],
        ];

        foreach ($matched as $dimension => $hits) {
            usort($hits, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);
            $limit = self::LIMITS[$dimension] ?? 3;
            $slugs[$dimension] = array_values(array_map(
                static fn (array $hit): string => $hit['slug'],
                array_slice($hits, 0, $limit),
            ));
        }

        return $slugs;
    }

    /**
     * Attach inferred catalogue terms onto a completed analysis (merge, do not wipe).
     *
     * @return array{attached: int, total: int}
     */
    public function backfillAnalysis(PostAnalysis $analysis): array
    {
        $inferredIds = $this->inferIdsFromAnalysis($analysis);

        if ($inferredIds === []) {
            return [
                'attached' => 0,
                'total' => $analysis->terms()->count(),
            ];
        }

        $existingIds = $analysis->terms()->pluck('analysis_terms.id')->map(fn ($id): int => (int) $id)->all();
        $mergedIds = array_values(array_unique(array_merge($existingIds, $inferredIds)));
        $analysis->terms()->sync($mergedIds);

        $labels = AnalysisTerm::query()
            ->whereIn('id', $inferredIds)
            ->orderBy('label')
            ->pluck('label')
            ->map(fn ($label): string => (string) $label)
            ->all();

        $topics = is_array($analysis->topics) ? $analysis->topics : [];
        $mergedTopics = array_values(array_unique(array_merge($topics, $labels)));

        if ($mergedTopics !== $topics) {
            $analysis->topics = $mergedTopics;
            $analysis->save();
        }

        return [
            'attached' => count(array_diff($inferredIds, $existingIds)),
            'total' => count($mergedIds),
        ];
    }

    /**
     * @param  list<string|null>  $parts
     */
    private function normalizeHaystack(array $parts): string
    {
        $joined = strtolower(implode(' ', array_filter(
            $parts,
            static fn (mixed $part): bool => is_string($part) && trim($part) !== '',
        )));

        $joined = str_replace(['/', '_'], [' ', ' '], $joined);
        $joined = preg_replace('/[^a-z0-9\s\-]+/u', ' ', $joined) ?? '';
        $joined = preg_replace('/\s+/', ' ', $joined) ?? '';

        return trim($joined);
    }

    private function matchScore(string $haystack, string $slug, string $label): int
    {
        $best = 0;

        foreach ($this->aliases($slug, $label) as $alias) {
            if ($alias === '' || strlen($alias) < 4) {
                continue;
            }

            if (! $this->containsAlias($haystack, $alias)) {
                continue;
            }

            $best = max($best, strlen($alias));
        }

        return $best;
    }

    /**
     * @return list<string>
     */
    private function aliases(string $slug, string $label): array
    {
        $labelNorm = strtolower(trim($label));
        $labelNorm = preg_replace('/[^a-z0-9\s\-]+/u', ' ', $labelNorm) ?? '';
        $labelNorm = trim(preg_replace('/\s+/', ' ', $labelNorm) ?? '');

        $slugSpaces = str_replace('_', ' ', strtolower($slug));
        $slugHyphen = str_replace('_', '-', strtolower($slug));
        $compact = preg_replace('/[^a-z0-9]/', '', $slugSpaces) ?? '';

        $candidates = [
            $labelNorm,
            $slugSpaces,
            $slugHyphen,
            $compact,
            $slugSpaces.'ing',
            $slugHyphen.'ing',
            $compact.'ing',
            str_replace(' ', '', $slugSpaces).'ing',
        ];

        // "myth bust" also matches "myth busting" / "myth-busting" via -ing forms above.
        return array_values(array_unique(array_filter(
            $candidates,
            static fn (string $alias): bool => $alias !== '',
        )));
    }

    private function containsAlias(string $haystack, string $alias): bool
    {
        $needle = strtolower($alias);

        if (str_contains($needle, ' ') || str_contains($needle, '-')) {
            $spaced = str_replace('-', ' ', $needle);
            $hyphen = str_replace(' ', '-', $spaced);

            return str_contains($haystack, $spaced) || str_contains($haystack, $hyphen);
        }

        // Compact tokens ("mythbusting") need a boundary-ish check via spaces around
        // a regex on the spaced haystack with the compact form collapsed.
        $compactHaystack = preg_replace('/[\s\-]+/', '', $haystack) ?? '';

        return str_contains($compactHaystack, $needle);
    }
}
