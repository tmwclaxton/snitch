<?php

namespace App\Services\Analysis;

use App\DataTransferObjects\VideoAnalysisResult;

class VideoAnalysisSuccessEvaluator
{
    /**
     * @return array{passed: bool, failures: list<string>}
     */
    public function evaluate(VideoAnalysisResult $result): array
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

        return [
            'passed' => $failures === [],
            'failures' => $failures,
        ];
    }
}
