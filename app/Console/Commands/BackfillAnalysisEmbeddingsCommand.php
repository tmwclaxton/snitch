<?php

namespace App\Console\Commands;

use App\Enums\AnalysisStatus;
use App\Jobs\EmbedPostAnalysisJob;
use App\Models\PostAnalysis;
use App\Services\Analysis\AnalysisEmbeddingService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('snitch:backfill-analysis-embeddings {--sync : Embed inline instead of queueing} {--force : Re-embed even when hash matches} {--limit=0 : Max analyses to process (0 = all)}')]
#[Description('Embed completed post analyses via NanoGPT for Explore semantic search')]
class BackfillAnalysisEmbeddingsCommand extends Command
{
    public function handle(AnalysisEmbeddingService $embeddings): int
    {
        if (! $embeddings->enabled()) {
            $this->error('Embeddings are disabled or NANOGPT_API_KEY is missing.');

            return self::FAILURE;
        }

        $sync = (bool) $this->option('sync');
        $force = (bool) $this->option('force');
        $limit = max(0, (int) $this->option('limit'));
        $queued = 0;
        $written = 0;
        $skipped = 0;
        $failed = 0;
        $scanned = 0;

        $query = PostAnalysis::query()
            ->where('status', AnalysisStatus::Completed)
            ->orderBy('id');

        if ($limit > 0) {
            $query->limit($limit);
        }

        $query->each(function (PostAnalysis $analysis) use (
            $embeddings,
            $sync,
            $force,
            &$queued,
            &$written,
            &$skipped,
            &$failed,
            &$scanned,
        ): void {
            $scanned++;

            if (! $sync) {
                EmbedPostAnalysisJob::dispatch($analysis->id);
                $queued++;

                return;
            }

            try {
                $analysis->loadMissing('post');

                if ($embeddings->embedAnalysis($analysis, $force)) {
                    $written++;
                    $this->line("analysis #{$analysis->id}: embedded");
                } else {
                    $skipped++;
                }
            } catch (Throwable $e) {
                $failed++;
                $this->warn("analysis #{$analysis->id}: ".$e->getMessage());
            }
        });

        if ($sync) {
            $this->info("Scanned {$scanned}; wrote {$written}; skipped {$skipped}; failed {$failed}.");
        } else {
            $this->info("Queued {$queued} EmbedPostAnalysisJob job(s) from {$scanned} analysis row(s).");
        }

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
