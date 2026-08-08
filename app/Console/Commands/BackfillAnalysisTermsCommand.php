<?php

namespace App\Console\Commands;

use App\Enums\AnalysisStatus;
use App\Models\PostAnalysis;
use App\Services\Analysis\AnalysisTermInferrer;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('snitch:backfill-analysis-terms {--dry-run : Show matches without writing}')]
#[Description('Infer catalogue taxonomy terms from freeform analysis text for explore filters')]
class BackfillAnalysisTermsCommand extends Command
{
    public function handle(AnalysisTermInferrer $inferrer): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $scanned = 0;
        $updated = 0;
        $attached = 0;

        PostAnalysis::query()
            ->where('status', AnalysisStatus::Completed)
            ->orderBy('id')
            ->each(function (PostAnalysis $analysis) use ($inferrer, $dryRun, &$scanned, &$updated, &$attached): void {
                $scanned++;

                if ($dryRun) {
                    $ids = $inferrer->inferIdsFromAnalysis($analysis);
                    $existing = $analysis->terms()->pluck('analysis_terms.id')->map(fn ($id): int => (int) $id)->all();
                    $new = array_values(array_diff($ids, $existing));

                    if ($new !== []) {
                        $updated++;
                        $attached += count($new);
                        $this->line("analysis #{$analysis->id} (post {$analysis->post_id}): +".count($new).' term(s)');
                    }

                    return;
                }

                $result = $inferrer->backfillAnalysis($analysis);

                if ($result['attached'] > 0) {
                    $updated++;
                    $attached += $result['attached'];
                    $this->line("analysis #{$analysis->id} (post {$analysis->post_id}): +{$result['attached']} term(s)");
                }
            });

        $prefix = $dryRun ? 'Dry run: ' : '';
        $this->info("{$prefix}scanned {$scanned}, updated {$updated}, attached {$attached} term link(s).");

        return self::SUCCESS;
    }
}
