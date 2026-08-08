<?php

namespace App\Console\Commands;

use App\Enums\AnalysisStatus;
use App\Models\PostAnalysis;
use App\Services\Analysis\AnalysisTermInferrer;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('snitch:backfill-analysis-terms {--dry-run : Show matches without writing} {--replace : Strip mirrored catalogue topics and replace pivot terms (do not merge)}')]
#[Description('Infer catalogue taxonomy terms from freeform analysis text for explore filters')]
class BackfillAnalysisTermsCommand extends Command
{
    public function handle(AnalysisTermInferrer $inferrer): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $replace = (bool) $this->option('replace');
        $scanned = 0;
        $updated = 0;
        $attached = 0;
        $detached = 0;

        PostAnalysis::query()
            ->where('status', AnalysisStatus::Completed)
            ->orderBy('id')
            ->each(function (PostAnalysis $analysis) use ($inferrer, $dryRun, $replace, &$scanned, &$updated, &$attached, &$detached): void {
                $scanned++;

                if ($dryRun) {
                    $topics = is_array($analysis->topics) ? $analysis->topics : [];
                    $extraIds = $replace ? array_values(array_unique(array_merge(
                        $inferrer->termIdsFromExactTopicSlugs($topics),
                        $inferrer->termIdsFromExactTopicLabels($topics, excludeHookTypes: true),
                    ))) : [];
                    $cleaned = $replace
                        ? $inferrer->topicsWithoutCatalogueMirrors($topics)
                        : $topics;

                    if ($replace && $cleaned !== $topics) {
                        $analysis->topics = $cleaned;
                    }

                    $ids = array_values(array_unique(array_merge(
                        $inferrer->inferIdsFromAnalysis($analysis),
                        $extraIds,
                    )));
                    $existing = $analysis->terms()->pluck('analysis_terms.id')->map(fn ($id): int => (int) $id)->all();
                    $next = $replace
                        ? $ids
                        : array_values(array_unique(array_merge($existing, $ids)));
                    $add = array_values(array_diff($next, $existing));
                    $remove = array_values(array_diff($existing, $next));

                    if ($add !== [] || $remove !== [] || ($replace && $cleaned !== $topics)) {
                        $updated++;
                        $attached += count($add);
                        $detached += count($remove);
                        $this->line(
                            "analysis #{$analysis->id} (post {$analysis->post_id}): +"
                            .count($add)
                            .' / -'
                            .count($remove)
                            .' term(s)',
                        );
                    }

                    return;
                }

                $result = $replace
                    ? $inferrer->replaceAnalysis($analysis)
                    : $inferrer->backfillAnalysis($analysis);

                if ($result['attached'] > 0 || $result['detached'] > 0) {
                    $updated++;
                    $attached += $result['attached'];
                    $detached += $result['detached'];
                    $this->line(
                        "analysis #{$analysis->id} (post {$analysis->post_id}): +"
                        .$result['attached']
                        .' / -'
                        .$result['detached']
                        .' term(s)',
                    );
                }
            });

        $prefix = $dryRun ? 'Dry run: ' : '';
        $this->info("{$prefix}scanned {$scanned}, updated {$updated}, attached {$attached}, detached {$detached} term link(s).");

        return self::SUCCESS;
    }
}
