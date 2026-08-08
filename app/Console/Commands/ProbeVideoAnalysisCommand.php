<?php

namespace App\Console\Commands;

use App\Services\Analysis\VideoAnalysisService;
use App\Services\Analysis\VideoAnalysisSuccessEvaluator;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('snitch:probe-video-analysis {url : Public media URL to analyze}')]
#[Description('Live NanoGPT video analysis checklist probe')]
class ProbeVideoAnalysisCommand extends Command
{
    public function handle(
        VideoAnalysisService $service,
        VideoAnalysisSuccessEvaluator $evaluator,
    ): int {
        if (! filter_var((string) env('SNITCH_LIVE_VIDEO', false), FILTER_VALIDATE_BOOLEAN)) {
            $this->warn('SNITCH_LIVE_VIDEO is not enabled. Skipping live probe.');

            return self::SUCCESS;
        }

        $url = (string) $this->argument('url');
        $this->info("Analyzing {$url}");

        $lower = strtolower($url);
        if (
            (str_contains($lower, 'youtube.com/') || str_contains($lower, 'youtu.be/'))
            && preg_match('/\.(mp4|webm|m3u8)(\?|$)/i', $url) !== 1
        ) {
            $this->error('KNOWN GAP: YouTube page/Shorts URLs are not downloadable media. Pass an MP4/webm URL.');

            return self::FAILURE;
        }

        $result = $service->analyzeUrl($url, 'video');
        $evaluation = $evaluator->evaluate($result);

        $this->line(json_encode($result->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        if (! $evaluation['passed']) {
            foreach ($evaluation['failures'] as $failure) {
                $this->error($failure);
            }

            return self::FAILURE;
        }

        $this->info('Video analysis checklist passed.');

        return self::SUCCESS;
    }
}
