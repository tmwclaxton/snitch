<?php

namespace App\Services\Analysis;

use App\DataTransferObjects\VideoAnalysisResult;
use App\Support\UsableAnalysisCopy;

class VideoAnalysisSuccessEvaluator
{
    /**
     * @return array{
     *     passed: bool,
     *     failures: list<string>,
     *     caption_echo: array{
     *         echoed: bool,
     *         score: float|null,
     *         reason: string|null,
     *         caption_coverage: float|null,
     *         analysis_reuse: float|null,
     *         threshold: float|null
     *     }|null
     * }
     */
    public function evaluate(VideoAnalysisResult $result, ?string $caption = null): array
    {
        $config = config('snitch.video_analysis.success');
        $failures = [];
        $captionEcho = null;

        if (strlen($result->hook) < (int) $config['min_hook_chars']) {
            $failures[] = 'hook too short';
        }

        if ($result->hookWindowEndSeconds < (float) $config['min_hook_window_end_seconds']) {
            $failures[] = 'hook window end below 3 seconds';
        }

        if (strlen($result->visualSummary) < (int) $config['min_visual_summary_chars']) {
            $failures[] = 'visual summary too short';
        }

        if (strlen($result->idea) < (int) $config['min_idea_chars']) {
            $failures[] = 'idea too short';
        }

        if (strlen($result->concept) < (int) ($config['min_concept_chars'] ?? 12)) {
            $failures[] = 'concept too short';
        }

        if ($config['require_sfx_array'] && ! is_array($result->sfx)) {
            $failures[] = 'sfx missing';
        }

        if ($config['require_sfx_labels_when_present']) {
            foreach ($result->sfx as $index => $item) {
                if (! is_array($item) || trim((string) ($item['label'] ?? '')) === '') {
                    $failures[] = "sfx[{$index}] missing label";
                }
            }
        }

        if ($config['require_cta_field'] && trim($result->cta) === '') {
            $failures[] = 'cta missing';
        }

        if (strlen($result->howToCopy) < (int) $config['require_how_to_copy_chars']) {
            $failures[] = 'how_to_copy too short';
        }

        if (trim($result->model) === '') {
            $failures[] = 'model missing';
        }

        if ($this->looksLikeGenericSlop($result)) {
            $failures[] = 'generic AI filler without named mechanic';
        }

        if ($this->looksLikePlaceholderCopy($result)) {
            $failures[] = 'placeholder or unprocessed copy';
        }

        if ($caption !== null) {
            $captionEcho = $this->assessCaptionEcho(
                $result,
                $caption,
                (float) ($config['max_caption_overlap_ratio'] ?? 0.65),
            );

            if ($captionEcho['echoed']) {
                $failures[] = 'analysis echoes caption/script too closely';
            }
        }

        // Short captions (e.g. "And so much more… #DoGoodGetFit") are too thin to
        // classify language against. Treat them as neutral and skip the English gate.
        if (! $this->captionTooShortToClassify($caption) && $this->containsNonEnglishProse($result)) {
            $failures[] = 'analysis must be English';
        }

        return [
            'passed' => $failures === [],
            'failures' => $failures,
            'caption_echo' => $captionEcho,
        ];
    }

    /**
     * Bag-of-words echo check over analytical craft fields only (not transcript /
     * quoted speech). Ignores stopwords, handles, hashtags, and proper nouns, and
     * raises the fail threshold for long recap captions.
     *
     * @return array{
     *     echoed: bool,
     *     score: float|null,
     *     reason: string|null,
     *     caption_coverage: float|null,
     *     analysis_reuse: float|null,
     *     threshold: float|null
     * }
     */
    public function assessCaptionEcho(VideoAnalysisResult $result, string $caption, ?float $baseMaxRatio = null): array
    {
        $config = config('snitch.video_analysis.success');
        $baseMaxRatio ??= (float) ($config['max_caption_overlap_ratio'] ?? 0.65);
        $threshold = $this->effectiveMaxRatio($caption, $baseMaxRatio);

        $empty = [
            'echoed' => false,
            'score' => null,
            'reason' => null,
            'caption_coverage' => null,
            'analysis_reuse' => null,
            'threshold' => $threshold,
        ];

        $captionTokens = $this->contentTokenSet($caption, preserveProperNounsFrom: $caption);

        if (count($captionTokens) < 8) {
            return $empty;
        }

        foreach (['hook' => $result->hook, 'idea' => $result->idea, 'concept' => $result->concept] as $fieldName => $field) {
            $fieldAssessment = $this->fieldEchoAssessment($field, $captionTokens, $threshold);

            if ($fieldAssessment['echoed']) {
                return [
                    'echoed' => true,
                    'score' => $fieldAssessment['score'],
                    'reason' => "near-verbatim dump in {$fieldName} (field_overlap={$fieldAssessment['score']}, threshold={$threshold})",
                    'caption_coverage' => null,
                    'analysis_reuse' => $fieldAssessment['score'],
                    'threshold' => $threshold,
                ];
            }
        }

        $analysisTokens = $this->contentTokenSet(
            $this->analyticalText($result),
            preserveProperNounsFrom: $caption,
        );

        if ($analysisTokens === []) {
            return $empty;
        }

        $overlap = count(array_intersect($captionTokens, $analysisTokens));
        $captionCoverage = $overlap / count($captionTokens);
        $analysisReuse = $overlap / count($analysisTokens);
        $score = max($captionCoverage, $analysisReuse);
        $echoed = $captionCoverage >= $threshold && $analysisReuse >= $threshold;

        return [
            'echoed' => $echoed,
            'score' => round($score, 4),
            'reason' => $echoed
                ? sprintf(
                    'caption_coverage=%.2f analysis_reuse=%.2f threshold=%.2f (analytical fields only)',
                    $captionCoverage,
                    $analysisReuse,
                    $threshold,
                )
                : null,
            'caption_coverage' => round($captionCoverage, 4),
            'analysis_reuse' => round($analysisReuse, 4),
            'threshold' => $threshold,
        ];
    }

    private function captionTooShortToClassify(?string $caption): bool
    {
        if ($caption === null) {
            return false;
        }

        $stripped = preg_replace('/https?:\/\/\S+/u', ' ', $caption) ?? '';
        $stripped = preg_replace('/[#@]\S+/u', ' ', $stripped) ?? '';
        $stripped = preg_replace('/[^\p{L}\s]/u', ' ', $stripped) ?? '';
        $parts = preg_split('/\s+/u', trim($stripped)) ?: [];
        $words = array_values(array_filter(
            $parts,
            static fn (string $word): bool => mb_strlen($word) >= 2,
        ));

        return count($words) < 5;
    }

    private function containsNonEnglishProse(VideoAnalysisResult $result): bool
    {
        $blob = implode(' ', [
            $result->concept,
            $result->idea,
            $result->visualSummary,
            $result->howToCopy,
            $result->cta,
            ...$result->topics,
        ]);

        foreach ($result->sfx as $item) {
            if (is_array($item)) {
                $blob .= ' '.((string) ($item['label'] ?? '')).' '.((string) ($item['role'] ?? ''));
            }
        }

        // Han / CJK Unified Ideographs (common failure mode for Qwen and similar models).
        return preg_match('/\p{Han}/u', $blob) === 1;
    }

    private function looksLikePlaceholderCopy(VideoAnalysisResult $result): bool
    {
        foreach ([$result->hook, $result->idea, $result->concept, $result->visualSummary, $result->howToCopy, $result->cta] as $field) {
            if (trim($field) !== '' && UsableAnalysisCopy::looksLikePlaceholder($field)) {
                return true;
            }
        }

        foreach ([$result->musicTitle, $result->musicArtist] as $field) {
            if (is_string($field) && trim($field) !== '' && UsableAnalysisCopy::looksLikePlaceholder($field)) {
                return true;
            }
        }

        return false;
    }

    private function looksLikeGenericSlop(VideoAnalysisResult $result): bool
    {
        $blob = strtolower(implode(' ', [
            $result->hook,
            $result->idea,
            $result->concept,
            $result->howToCopy,
        ]));

        $slopPhrases = [
            'engaging content',
            'relatable vibe',
            'great energy',
            'post more consistently',
            'high quality content',
            'captures attention',
            'keeps viewers hooked',
        ];

        $hits = 0;
        foreach ($slopPhrases as $phrase) {
            if (str_contains($blob, $phrase)) {
                $hits++;
            }
        }

        return $hits >= 2;
    }

    /**
     * Craft fields only - transcript and quoted spoken lines are excluded so a
     * long recap caption does not fail a genuine analytical writeup.
     */
    private function analyticalText(VideoAnalysisResult $result): string
    {
        $parts = [
            $result->hook,
            $result->idea,
            $result->concept,
            $result->visualSummary,
            $result->howToCopy,
            $result->cta,
            ...$result->topics,
        ];

        return implode(' ', array_map(
            fn (string $part): string => $this->stripQuotedText($part),
            $parts,
        ));
    }

    private function stripQuotedText(string $text): string
    {
        $stripped = preg_replace('/"([^"\\\\]|\\\\.)*"/u', ' ', $text) ?? $text;
        $stripped = preg_replace("/'([^'\\\\]|\\\\.)*'/u", ' ', $stripped) ?? $stripped;

        return $stripped;
    }

    /**
     * @param  list<string>  $captionTokens
     * @return array{echoed: bool, score: float|null}
     */
    private function fieldEchoAssessment(string $field, array $captionTokens, float $threshold): array
    {
        $fieldTokens = $this->contentTokenSet($this->stripQuotedText($field));

        if (count($fieldTokens) < 5) {
            return ['echoed' => false, 'score' => null];
        }

        $overlap = count(array_intersect($fieldTokens, $captionTokens));
        $score = round($overlap / count($fieldTokens), 4);
        // Near-verbatim field dumps stay strict; long-caption threshold only softens bag-of-words.
        $fieldThreshold = max(0.85, $threshold);

        return [
            'echoed' => $score >= $fieldThreshold,
            'score' => $score,
        ];
    }

    private function effectiveMaxRatio(string $caption, float $baseRatio): float
    {
        $config = config('snitch.video_analysis.success');
        $longChars = (int) ($config['long_caption_chars'] ?? 300);
        $ceiling = (float) ($config['long_caption_max_overlap_ratio'] ?? 0.85);
        $len = mb_strlen($caption);

        if ($len <= $longChars) {
            return $baseRatio;
        }

        // Linear ease from base at longChars toward ceiling over the next ~700 chars.
        $span = max(1, (int) ($config['long_caption_scale_span_chars'] ?? 700));
        $progress = min(1.0, ($len - $longChars) / $span);

        return min($ceiling, $baseRatio + (($ceiling - $baseRatio) * $progress));
    }

    /**
     * @return list<string>
     */
    private function contentTokenSet(string $text, ?string $preserveProperNounsFrom = null): array
    {
        return array_values(array_unique($this->contentTokens($text, $preserveProperNounsFrom)));
    }

    /**
     * @return list<string>
     */
    private function contentTokens(string $text, ?string $preserveProperNounsFrom = null): array
    {
        $properNouns = $this->properNounSet($preserveProperNounsFrom ?? $text);
        $withoutHandles = preg_replace('/https?:\/\/\S+/u', ' ', $text) ?? $text;
        $withoutHandles = preg_replace('/[#@]\S+/u', ' ', $withoutHandles) ?? $withoutHandles;
        $normalized = strtolower(preg_replace('/[^a-z0-9\s]/i', ' ', $withoutHandles) ?? '');
        $parts = preg_split('/\s+/', trim($normalized)) ?: [];
        $stop = $this->stopwords();

        return array_values(array_filter(
            $parts,
            static fn (string $token): bool => strlen($token) > 2
                && ! in_array($token, $stop, true)
                && ! isset($properNouns[$token]),
        ));
    }

    /**
     * Lowercased tokens that look like proper nouns / event names in the source text.
     *
     * @return array<string, true>
     */
    private function properNounSet(string $text): array
    {
        $withoutHandles = preg_replace('/https?:\/\/\S+/u', ' ', $text) ?? $text;
        $withoutHandles = preg_replace('/[#@]\S+/u', ' ', $withoutHandles) ?? $withoutHandles;
        $parts = preg_split('/\s+/u', trim($withoutHandles)) ?: [];
        $proper = [];
        $sentenceStart = true;

        foreach ($parts as $raw) {
            $clean = preg_replace('/[^a-zA-Z0-9]/', '', $raw) ?? '';

            if ($clean === '') {
                if (preg_match('/[.!?]$/u', $raw) === 1) {
                    $sentenceStart = true;
                }

                continue;
            }

            $lower = strtolower($clean);
            $isCapitalized = preg_match('/^[A-Z]/', $clean) === 1
                && preg_match('/[a-z]/', $clean) === 1;

            if ($isCapitalized && ! $sentenceStart && strlen($lower) > 2) {
                $proper[$lower] = true;
            }

            $sentenceStart = preg_match('/[.!?]$/u', $raw) === 1;
        }

        return $proper;
    }

    /**
     * @return list<string>
     */
    private function stopwords(): array
    {
        return [
            'the', 'and', 'for', 'with', 'that', 'this', 'from', 'your', 'you', 'are', 'was', 'were',
            'have', 'has', 'had', 'been', 'being', 'they', 'them', 'their', 'our', 'ours', 'his', 'her',
            'hers', 'its', 'who', 'what', 'when', 'where', 'why', 'how', 'all', 'any', 'both', 'each',
            'few', 'more', 'most', 'other', 'some', 'such', 'than', 'too', 'very', 'can', 'will', 'just',
            'should', 'now', 'about', 'into', 'over', 'after', 'before', 'between', 'through', 'during',
            'without', 'under', 'again', 'further', 'then', 'once', 'here', 'there', 'out', 'off', 'above',
            'below', 'down', 'but', 'not', 'yes', 'did', 'does', 'doing', 'done', 'got', 'get', 'getting',
            'went', 'going', 'came', 'come', 'coming', 'last', 'next', 'also', 'really', 'like', 'make',
            'made', 'making', 'people', 'everyone', 'someone', 'anyone', 'thing', 'things', 'week',
            'weekend', 'night', 'nights', 'day', 'days', 'friday', 'saturday', 'sunday', 'monday',
            'tuesday', 'wednesday', 'thursday', 'today', 'tomorrow', 'yesterday', 'would', 'could',
            'across', 'around', 'among', 'along', 'onto', 'upon', 'near', 'via', 'per', 'plus', 'still',
            'even', 'ever', 'never', 'always', 'often', 'together', 'back', 'away', 'let', 'lets',
            'thank', 'thanks', 'huge', 'exactly', 'follow', 'save', 'share', 'tag', 'link', 'bio',
        ];
    }
}
