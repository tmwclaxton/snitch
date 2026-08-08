<?php

namespace App\Console\Commands;

use App\Enums\Platform;
use App\Services\Analysis\VideoAnalysisService;
use App\Services\Analysis\VideoAnalysisSuccessEvaluator;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('snitch:probe-analysis-matrix {--platform=* : Platforms to label samples} {--url=* : Downloadable MP4/reel media URLs (same order as --platform)}')]
#[Description('Per-platform concept-first video analysis rubric smoke (live)')]
class ProbeAnalysisMatrixCommand extends Command
{
    public function handle(
        VideoAnalysisService $service,
        VideoAnalysisSuccessEvaluator $evaluator,
    ): int {
        $live = filter_var((string) env('SNITCH_LIVE_ANALYSIS_MATRIX', false), FILTER_VALIDATE_BOOLEAN)
            || filter_var((string) env('SNITCH_LIVE_VIDEO', false), FILTER_VALIDATE_BOOLEAN);

        if (! $live) {
            $this->warn('SNITCH_LIVE_ANALYSIS_MATRIX (or SNITCH_LIVE_VIDEO) is not enabled. Skipping.');

            return self::SUCCESS;
        }

        $platforms = array_values(array_filter((array) $this->option('platform')));
        $urls = array_values(array_filter((array) $this->option('url')));

        if ($platforms === [] || $urls === [] || count($platforms) !== count($urls)) {
            $this->error('Provide matching --platform and --url pairs (downloadable media, not YouTube page URLs).');

            return self::FAILURE;
        }

        $this->info('Analysis matrix: concept-first rubric; reject script dump / AI slop.');
        $this->warn('KNOWN GAP: YouTube Shorts page URLs fail here - pass a downloadable MP4 if testing youtube.');

        $failed = 0;

        foreach ($platforms as $index => $platformValue) {
            $platform = Platform::tryFrom((string) $platformValue);
            $url = (string) $urls[$index];

            if ($platform === null) {
                $this->error("Invalid platform: {$platformValue}");
                $failed++;

                continue;
            }

            if ($this->looksLikeYoutubePageUrl($url)) {
                $this->error("[{$platform->value}] Skipping page URL (needs downloadable MP4): {$url}");
                $failed++;

                continue;
            }

            $this->info("[{$platform->value}] Analyzing {$url}");

            try {
                $result = $service->analyzeUrl($url, 'video');
                $evaluation = $evaluator->evaluate($result);
            } catch (\Throwable $e) {
                $this->error("[{$platform->value}] {$e->getMessage()}");
                $failed++;

                continue;
            }

            $payload = [
                'platform' => $platform->value,
                'concept' => $result->concept,
                'hook' => $result->hook,
                'idea' => $result->idea,
                'how_to_copy' => $result->howToCopy,
                'topics' => $result->topics,
                'passed' => $evaluation['passed'],
                'failures' => $evaluation['failures'],
            ];

            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            if (! $evaluation['passed']) {
                $failed++;
            }
        }

        if ($failed > 0) {
            $this->error("Analysis matrix failed {$failed} sample(s).");

            return self::FAILURE;
        }

        $this->info('Analysis matrix passed for all samples.');

        return self::SUCCESS;
    }

    private function looksLikeYoutubePageUrl(string $url): bool
    {
        $lower = strtolower($url);

        if (! str_contains($lower, 'youtube.com/') && ! str_contains($lower, 'youtu.be/')) {
            return false;
        }

        return preg_match('/\.(mp4|webm|m3u8)(\?|$)/i', $url) !== 1;
    }
}
