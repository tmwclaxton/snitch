<?php

namespace App\Services\Analysis;

use App\DataTransferObjects\VideoAnalysisResult;

class VideoAnalysisSuccessEvaluator
{
    /**
     * @return array{passed: bool, failures: list<string>}
     */
    public function evaluate(VideoAnalysisResult $result, ?string $caption = null): array
    {
        $config = config('snitch.video_analysis.success');
        $failures = [];

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

        if ($caption !== null && $this->looksLikeCaptionEcho($result, $caption, (float) ($config['max_caption_overlap_ratio'] ?? 0.65))) {
            $failures[] = 'analysis echoes caption/script too closely';
        }

        if ($this->containsNonEnglishProse($result)) {
            $failures[] = 'analysis must be English';
        }

        return [
            'passed' => $failures === [],
            'failures' => $failures,
        ];
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

    private function looksLikeCaptionEcho(VideoAnalysisResult $result, string $caption, float $maxRatio): bool
    {
        $captionTokens = $this->tokenSet($caption);

        if (count($captionTokens) < 8) {
            return false;
        }

        // Near-verbatim dumps of the caption into a primary field.
        foreach ([$result->hook, $result->idea, $result->concept] as $field) {
            if ($this->fieldEchoesCaption($field, $captionTokens)) {
                return true;
            }
        }

        $analysisTokens = $this->tokenSet(implode(' ', [
            $result->hook,
            $result->idea,
            $result->concept,
            $result->visualSummary,
            $result->howToCopy,
        ]));

        if ($analysisTokens === []) {
            return false;
        }

        $overlap = count(array_intersect($captionTokens, $analysisTokens));
        $captionCoverage = $overlap / count($captionTokens);
        // Short topic-dense captions (proper nouns, product names) often appear in a
        // good craft writeup. Only fail bag-of-words when the analysis itself is
        // mostly caption tokens, not merely when it mentions the subject.
        $analysisReuse = $overlap / count($analysisTokens);

        return $captionCoverage >= $maxRatio && $analysisReuse >= $maxRatio;
    }

    /**
     * @param  list<string>  $captionTokens
     */
    private function fieldEchoesCaption(string $field, array $captionTokens): bool
    {
        $fieldTokens = $this->tokenSet($field);

        if (count($fieldTokens) < 6) {
            return false;
        }

        $overlap = count(array_intersect($fieldTokens, $captionTokens));

        return ($overlap / count($fieldTokens)) >= 0.85;
    }

    /**
     * @return list<string>
     */
    private function tokenSet(string $text): array
    {
        return array_values(array_unique($this->tokens($text)));
    }

    /**
     * @return list<string>
     */
    private function tokens(string $text): array
    {
        $normalized = strtolower(preg_replace('/[^a-z0-9\s]/i', ' ', $text) ?? '');
        $parts = preg_split('/\s+/', trim($normalized)) ?: [];

        $stop = ['the', 'and', 'for', 'with', 'that', 'this', 'from', 'your', 'you', 'are', 'was', 'were', 'have', 'has'];

        return array_values(array_filter(
            $parts,
            static fn (string $token): bool => strlen($token) > 2 && ! in_array($token, $stop, true),
        ));
    }
}
