<?php

namespace App\Jobs;

use App\Enums\AnalysisStatus;
use App\Exceptions\InsufficientCreditsException;
use App\Exceptions\PlatformSubscriptionRequiredException;
use App\Models\PostAnalysis;
use App\Services\Analysis\AnalysisEmbeddingService;
use App\Services\Billing\UsageBillingService;
use App\Services\Billing\VendorUsageCharger;
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

    public function handle(
        AnalysisEmbeddingService $embeddings,
        VendorUsageCharger $charger,
        UsageBillingService $billing,
    ): void {
        $analysis = PostAnalysis::query()
            ->with('post.user')
            ->find($this->postAnalysisId);

        if ($analysis === null) {
            return;
        }

        if ($analysis->status !== AnalysisStatus::Completed) {
            return;
        }

        $owner = $analysis->post?->user;

        if ($owner === null) {
            return;
        }

        try {
            $charger->assertCanRun($owner);
        } catch (PlatformSubscriptionRequiredException|InsufficientCreditsException $e) {
            Log::info('EmbedPostAnalysisJob skipped; billing gate', [
                'post_analysis_id' => $this->postAnalysisId,
                'error' => $e->getMessage(),
            ]);

            return;
        }

        try {
            $embeddings->embedAnalysis($analysis);
            $charger->chargeNanoGpt(
                user: $owner,
                action: 'embed.analysis',
                cogsUsd: $billing->estimateNanoGptChatUsd(
                    null,
                    null,
                    (string) config('snitch.embeddings.model', 'text-embedding-3-small'),
                    'embeddings',
                ),
                meta: ['post_analysis_id' => $analysis->id],
                idempotencyKey: 'embed.analysis:'.$analysis->id,
            );
        } catch (Throwable $e) {
            Log::warning('EmbedPostAnalysisJob failed', [
                'post_analysis_id' => $this->postAnalysisId,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
