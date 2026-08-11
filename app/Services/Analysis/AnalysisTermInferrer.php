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
        // VFX-heavy posts often stack effects + a layout/cam craft; 3 was dropping glitch/particles.
        'visual_craft' => 4,
    ];

    /**
     * Extra freeform phrases that should map to catalogue slugs.
     * Short aliases (3+ chars) are allowed here; generated aliases still require 4+.
     *
     * @var array<string, list<string>>
     */
    private const EXTRA_ALIASES = [
        'vhs_glitch' => ['glitch', 'vhs', 'datamosh', 'rgb split', 'vhs glitch'],
        'greenscreen' => ['green screen', 'chroma key', 'greenscreen'],
        'particle_fx' => ['particles', 'particle fx', 'sparkle fx', 'confetti fx', 'particle effect'],
        'motion_graphics' => ['motion graphic', 'animated graphic', 'mg animation', 'motion graphics'],
        'screen_distort' => ['screen warp', 'screen distort', 'liquid warp', 'warp effect'],
        'light_leak_flare' => ['light leak', 'lens flare', 'light leak flare'],
        'object_tracking' => ['object track', 'tracking overlay', 'motion track', 'tracked overlay'],
        'ai_face_filter' => ['face filter', 'beauty filter', 'ai filter', 'ai face'],
        'capcut_template_fx' => ['capcut', 'template fx', 'effect pack', 'capcut effect'],
        'vfx_composite' => ['vfx', 'visual effects', 'visual effect', 'compositing', 'composite vfx'],
        'sticker_pack_overlay' => ['sticker overlay', 'sticker pack', 'animated stickers'],
        'emoji_bursts' => ['emoji burst', 'emoji rain', 'emoji overlay'],
        'film_grain' => ['film grain', 'grain overlay'],
        'duotone_grade' => ['duotone'],
        'neon_accent' => ['neon lighting', 'neon accent'],
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
            // Omit idea: models often narrate taxonomy slugs there ("uses a myth_bust mechanism"),
            // which creates false-positive hook filters.
            'hook_type' => $this->normalizeHaystack([
                $fields['hook'] ?? null,
                $fields['concept'] ?? null,
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
                ...($fields['custom_tags'] ?? []),
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
     * @return array{attached: int, detached: int, total: int}
     */
    public function backfillAnalysis(PostAnalysis $analysis): array
    {
        return $this->applyInferredTerms($analysis, replace: false);
    }

    /**
     * Strip mirrored catalogue labels from topics, re-infer, and replace pivot terms.
     * Use after a bad merge backfill that polluted topics and attached false positives.
     * Exact catalogue slugs still present in topics are kept as term links.
     *
     * @return array{attached: int, detached: int, total: int}
     */
    public function replaceAnalysis(PostAnalysis $analysis): array
    {
        $topics = is_array($analysis->topics) ? $analysis->topics : [];
        $extraIds = array_values(array_unique(array_merge(
            $this->termIdsFromExactTopicSlugs($topics),
            $this->termIdsFromExactTopicLabels($topics, excludeHookTypes: true),
        )));
        $cleanedTopics = $this->topicsWithoutCatalogueMirrors($topics);

        if ($cleanedTopics !== $topics) {
            $analysis->topics = $cleanedTopics;
            $analysis->save();
        }

        return $this->applyInferredTerms(
            $analysis->fresh() ?? $analysis,
            replace: true,
            extraIds: $extraIds,
        );
    }

    /**
     * @param  list<int>  $extraIds
     * @return array{attached: int, detached: int, total: int}
     */
    private function applyInferredTerms(PostAnalysis $analysis, bool $replace, array $extraIds = []): array
    {
        $inferredIds = $this->inferIdsFromAnalysis($analysis);
        $existingIds = $analysis->terms()->pluck('analysis_terms.id')->map(fn ($id): int => (int) $id)->all();

        $candidateIds = array_values(array_unique(array_merge($inferredIds, $extraIds)));
        $nextIds = $replace
            ? $candidateIds
            : array_values(array_unique(array_merge($existingIds, $candidateIds)));

        $analysis->terms()->sync($nextIds);

        $labels = $nextIds === []
            ? []
            : AnalysisTerm::query()
                ->whereIn('id', $nextIds)
                ->orderBy('label')
                ->pluck('label')
                ->map(fn ($label): string => (string) $label)
                ->all();

        $topics = is_array($analysis->topics) ? $analysis->topics : [];
        $baseTopics = $replace
            ? $this->topicsWithoutCatalogueMirrors($topics)
            : $topics;
        $mergedTopics = array_values(array_unique(array_merge($baseTopics, $labels)));

        if ($mergedTopics !== $topics) {
            $analysis->topics = $mergedTopics;
            $analysis->save();
        }

        return [
            'attached' => count(array_diff($nextIds, $existingIds)),
            'detached' => count(array_diff($existingIds, $nextIds)),
            'total' => count($nextIds),
        ];
    }

    /**
     * Drop topics that are only mirrored catalogue labels/slugs (keeps freeform phrases).
     *
     * @param  list<string>  $topics
     * @return list<string>
     */
    public function topicsWithoutCatalogueMirrors(array $topics): array
    {
        $mirrors = [];

        foreach ($this->catalogue->definitions() as $row) {
            $mirrors[strtolower(trim($row['label']))] = true;
            $mirrors[strtolower(trim($row['slug']))] = true;
            $mirrors[str_replace('_', ' ', strtolower(trim($row['slug'])))] = true;
            $mirrors[str_replace('_', '-', strtolower(trim($row['slug'])))] = true;
        }

        $kept = [];

        foreach ($topics as $topic) {
            if (! is_string($topic)) {
                continue;
            }

            $trimmed = trim($topic);

            if ($trimmed === '') {
                continue;
            }

            $key = strtolower($trimmed);

            if (isset($mirrors[$key])) {
                continue;
            }

            $kept[] = $trimmed;
        }

        return array_values(array_unique($kept));
    }

    /**
     * Resolve catalogue term IDs from topics that look like canonical slugs
     * (`personal_brand` / `personal-brand`), not mirrored labels (`Personal brand`).
     *
     * @param  list<string>  $topics
     * @return list<int>
     */
    public function termIdsFromExactTopicSlugs(array $topics): array
    {
        $byDimension = [
            'hook_type' => [],
            'topic' => [],
            'visual_craft' => [],
        ];

        $slugIndex = [];

        foreach ($this->catalogue->definitions() as $row) {
            $slug = strtolower(trim($row['slug']));
            $slugIndex[$slug] = $row['dimension'];
            $slugIndex[str_replace('_', '-', $slug)] = $row['dimension'];
        }

        foreach ($topics as $topic) {
            if (! is_string($topic)) {
                continue;
            }

            $key = strtolower(trim($topic));

            // Slug-shaped only: avoid promoting mirrored Title Case labels.
            if ($key === '' || preg_match('/^[a-z0-9]+(?:[_-][a-z0-9]+)*$/', $key) !== 1) {
                continue;
            }

            if (! isset($slugIndex[$key])) {
                continue;
            }

            $dimension = $slugIndex[$key];
            $byDimension[$dimension][] = str_replace('-', '_', $key);
        }

        return array_values(array_unique(array_merge(
            $this->catalogue->resolveIds(AnalysisTermDimension::HookType, $byDimension['hook_type']),
            $this->catalogue->resolveIds(AnalysisTermDimension::Topic, $byDimension['topic']),
            $this->catalogue->resolveIds(AnalysisTermDimension::VisualCraft, $byDimension['visual_craft']),
        )));
    }

    /**
     * Resolve term IDs from topics that exactly match catalogue labels.
     * When $excludeHookTypes is true, skip hook_type labels so remirrored false
     * positives like "Myth bust" are not re-attached during --replace.
     *
     * @param  list<string>  $topics
     * @return list<int>
     */
    public function termIdsFromExactTopicLabels(array $topics, bool $excludeHookTypes = false): array
    {
        $byDimension = [
            'hook_type' => [],
            'topic' => [],
            'visual_craft' => [],
        ];

        $labelIndex = [];

        foreach ($this->catalogue->definitions() as $row) {
            if ($excludeHookTypes && $row['dimension'] === 'hook_type') {
                continue;
            }

            $labelIndex[strtolower(trim($row['label']))] = $row;
        }

        foreach ($topics as $topic) {
            if (! is_string($topic)) {
                continue;
            }

            $key = strtolower(trim($topic));

            if ($key === '' || ! isset($labelIndex[$key])) {
                continue;
            }

            $row = $labelIndex[$key];
            $byDimension[$row['dimension']][] = $row['slug'];
        }

        return array_values(array_unique(array_merge(
            $this->catalogue->resolveIds(AnalysisTermDimension::HookType, $byDimension['hook_type']),
            $this->catalogue->resolveIds(AnalysisTermDimension::Topic, $byDimension['topic']),
            $this->catalogue->resolveIds(AnalysisTermDimension::VisualCraft, $byDimension['visual_craft']),
        )));
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
        $extra = self::EXTRA_ALIASES[$slug] ?? [];

        foreach ($this->aliases($slug, $label) as $alias) {
            $minLength = in_array($alias, $extra, true) ? 3 : 4;

            if ($alias === '' || strlen($alias) < $minLength) {
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
            ...(self::EXTRA_ALIASES[$slug] ?? []),
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
