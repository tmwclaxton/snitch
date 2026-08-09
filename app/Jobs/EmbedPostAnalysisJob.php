<?php

namespace App\Jobs;

use App\Enums\AnalysisStatus;
use App\Models\PostAnalysis;
use App\Services\Analysis\AnalysisEmbeddingService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class EmbedPostAnalysisJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [5, 30, 120];

    public function __construct(public int $postAnalysisId) {}

    public function handle(AnalysisEmbeddingService $embeddings): void
    {
        $analysis = PostAnalysis::query()
            ->with('post')
            ->find($this->postAnalysisId);

        if ($analysis === null) {
            return;
        }

        if ($analysis->status !== AnalysisStatus::Completed) {
            return;
        }

        try {
            $embeddings->embedAnalysis($analysis);
        } catch (Throwable $e) {
            Log::warning('EmbedPostAnalysisJob failed', [
                'post_analysis_id' => $this->postAnalysisId,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
