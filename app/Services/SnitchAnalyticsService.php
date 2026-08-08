<?php

namespace App\Services;

use App\Enums\AnalysisStatus;
use App\Enums\AnalysisTermDimension;
use App\Enums\Platform;
use App\Models\AnalysisTerm;
use App\Models\SnitchDailyPlatformStat;
use App\Models\SnitchDailyStat;
use App\Services\Analysis\AnalysisTermCatalogue;
use App\Support\AnalyticsDateRange;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

class SnitchAnalyticsService
{
    public const TOP_TERMS_PER_DIMENSION = 8;

    public function __construct(
        private AnalysisTermCatalogue $catalogue,
    ) {}

    public function recordPostSynced(Platform $platform, int $count = 1, ?CarbonInterface $on = null): void
    {
        if ($count < 1) {
            return;
        }

        $date = ($on ?? now())->copy()->startOfDay();

        $this->incrementDailyStat('posts_count', $count, $date);

        $platformStat = SnitchDailyPlatformStat::query()->firstOrCreate(
            [
                'date' => $date,
                'platform' => $platform,
            ],
            ['posts_count' => 0],
        );

        $platformStat->increment('posts_count', $count);
    }

    public function recordAnalysisCompleted(int $count = 1, ?CarbonInterface $on = null): void
    {
        $this->incrementDailyStat('analyses_count', $count, $on);
    }

    public function recordWinnerScored(int $count = 1, ?CarbonInterface $on = null): void
    {
        $this->incrementDailyStat('winners_count', $count, $on);
    }

    /**
     * @return array{
     *     days: int,
     *     range: array{
     *         month: string,
     *         days: int,
     *         from: string,
     *         to: string,
     *         label: string,
     *         prev_month: string|null,
     *         next_month: string|null,
     *         can_go_prev: bool,
     *         can_go_next: bool,
     *         min_days: int,
     *         max_days: int,
     *     },
     *     metrics: array{
     *         posts_synced: array{
     *             label: string,
     *             total: int,
     *             period_total: int,
     *             series: array<int, array{date: string, count: int}>,
     *         },
     *         analyses_completed: array{
     *             label: string,
     *             total: int,
     *             period_total: int,
     *             series: array<int, array{date: string, count: int}>,
     *         },
     *         winners_scored: array{
     *             label: string,
     *             total: int,
     *             period_total: int,
     *         },
     *     },
     *     platforms: list<array{platform: string, label: string, count: int}>,
     *     top_terms: array{
     *         hook_type: list<array{slug: string, label: string, section: string|null, count: int}>,
     *         topic: list<array{slug: string, label: string, section: string|null, count: int}>,
     *         visual_craft: list<array{slug: string, label: string, section: string|null, count: int}>,
     *     },
     * }
     */
    public function publicSummary(?AnalyticsDateRange $range = null): array
    {
        $range ??= AnalyticsDateRange::fromInput(null, null);
        $from = $range->from->copy()->startOfDay();
        $to = $range->to->copy()->startOfDay();
        $days = $range->days();

        $postsByDate = [];
        $analysesByDate = [];
        $winnersByDate = [];

        foreach (
            SnitchDailyStat::query()
                ->whereDate('date', '>=', $from->toDateString())
                ->whereDate('date', '<=', $to->toDateString())
                ->orderBy('date')
                ->get() as $stat
        ) {
            $date = $stat->date->toDateString();
            $postsByDate[$date] = (int) $stat->posts_count;
            $analysesByDate[$date] = (int) $stat->analyses_count;
            $winnersByDate[$date] = (int) $stat->winners_count;
        }

        return [
            'days' => $days,
            'range' => $range->meta(),
            'metrics' => [
                'posts_synced' => $this->buildMetricSeries(
                    $days,
                    $from,
                    $postsByDate,
                    'Posts synced',
                    (int) SnitchDailyStat::query()->sum('posts_count'),
                ),
                'analyses_completed' => $this->buildMetricSeries(
                    $days,
                    $from,
                    $analysesByDate,
                    'Analyses completed',
                    (int) SnitchDailyStat::query()->sum('analyses_count'),
                ),
                'winners_scored' => [
                    'label' => 'Winners scored',
                    'total' => (int) SnitchDailyStat::query()->sum('winners_count'),
                    'period_total' => $this->sumCountsInRange($days, $from, $winnersByDate),
                ],
            ],
            'platforms' => $this->platformMix($from, $to),
            'top_terms' => $this->topTerms($from, $to),
        ];
    }

    /**
     * Rebuild daily counters from existing domain rows (idempotent wipe + refill).
     */
    public function backfillFromDomain(): array
    {
        SnitchDailyStat::query()->delete();
        SnitchDailyPlatformStat::query()->delete();

        $posts = 0;
        $analyses = 0;
        $winners = 0;

        foreach (
            DB::table('posts')
                ->selectRaw('DATE(created_at) as day, platform, COUNT(*) as aggregate')
                ->groupByRaw('DATE(created_at), platform')
                ->orderBy('day')
                ->get() as $row
        ) {
            $platform = Platform::tryFrom((string) $row->platform);

            if ($platform === null) {
                continue;
            }

            $count = (int) $row->aggregate;
            $posts += $count;
            $this->recordPostSynced($platform, $count, Carbon::parse((string) $row->day)->startOfDay());
        }

        foreach (
            DB::table('post_analyses')
                ->where('status', AnalysisStatus::Completed->value)
                ->whereNotNull('analyzed_at')
                ->selectRaw('DATE(analyzed_at) as day, COUNT(*) as aggregate')
                ->groupByRaw('DATE(analyzed_at)')
                ->orderBy('day')
                ->get() as $row
        ) {
            $count = (int) $row->aggregate;
            $analyses += $count;
            $this->recordAnalysisCompleted($count, Carbon::parse((string) $row->day)->startOfDay());
        }

        foreach (
            DB::table('winner_insights')
                ->selectRaw('DATE(created_at) as day, COUNT(*) as aggregate')
                ->groupByRaw('DATE(created_at)')
                ->orderBy('day')
                ->get() as $row
        ) {
            $count = (int) $row->aggregate;
            $winners += $count;
            $this->recordWinnerScored($count, Carbon::parse((string) $row->day)->startOfDay());
        }

        return [
            'posts' => $posts,
            'analyses' => $analyses,
            'winners' => $winners,
        ];
    }

    private function incrementDailyStat(string $column, int $count, ?CarbonInterface $on = null): void
    {
        if ($count < 1) {
            return;
        }

        $date = ($on ?? now())->copy()->startOfDay();

        $stat = SnitchDailyStat::query()->firstOrCreate(
            ['date' => $date],
            [
                'posts_count' => 0,
                'analyses_count' => 0,
                'winners_count' => 0,
            ],
        );

        $stat->increment($column, $count);
    }

    /**
     * @return list<array{platform: string, label: string, count: int}>
     */
    private function platformMix(CarbonInterface $from, CarbonInterface $to): array
    {
        $counts = [];

        foreach (
            SnitchDailyPlatformStat::query()
                ->whereDate('date', '>=', $from->toDateString())
                ->whereDate('date', '<=', $to->toDateString())
                ->selectRaw('platform, SUM(posts_count) as aggregate')
                ->groupBy('platform')
                ->get() as $row
        ) {
            $key = $row->platform instanceof Platform
                ? $row->platform->value
                : (string) $row->platform;
            $counts[$key] = (int) $row->aggregate;
        }

        $mix = [];

        foreach (Platform::cases() as $platform) {
            $count = (int) ($counts[$platform->value] ?? 0);

            if ($count < 1) {
                continue;
            }

            $mix[] = [
                'platform' => $platform->value,
                'label' => $platform->label(),
                'count' => $count,
            ];
        }

        usort($mix, static fn (array $a, array $b): int => $b['count'] <=> $a['count']);

        return $mix;
    }

    /**
     * @return array{
     *     hook_type: list<array{slug: string, label: string, section: string|null, count: int}>,
     *     topic: list<array{slug: string, label: string, section: string|null, count: int}>,
     *     visual_craft: list<array{slug: string, label: string, section: string|null, count: int}>,
     * }
     */
    private function topTerms(CarbonInterface $from, CarbonInterface $to): array
    {
        $result = [
            AnalysisTermDimension::HookType->value => [],
            AnalysisTermDimension::Topic->value => [],
            AnalysisTermDimension::VisualCraft->value => [],
        ];
        $sections = $this->catalogue->sectionByKey();

        $rows = AnalysisTerm::query()
            ->select([
                'analysis_terms.dimension',
                'analysis_terms.slug',
                'analysis_terms.label',
                DB::raw('COUNT(*) as aggregate'),
            ])
            ->join('analysis_term_post_analysis', 'analysis_terms.id', '=', 'analysis_term_post_analysis.analysis_term_id')
            ->join('post_analyses', 'post_analyses.id', '=', 'analysis_term_post_analysis.post_analysis_id')
            ->where('post_analyses.status', AnalysisStatus::Completed)
            ->whereNotNull('post_analyses.analyzed_at')
            ->whereDate('post_analyses.analyzed_at', '>=', $from->toDateString())
            ->whereDate('post_analyses.analyzed_at', '<=', $to->toDateString())
            ->groupBy('analysis_terms.id', 'analysis_terms.dimension', 'analysis_terms.slug', 'analysis_terms.label')
            ->orderByDesc('aggregate')
            ->get();

        $perDimension = [
            AnalysisTermDimension::HookType->value => 0,
            AnalysisTermDimension::Topic->value => 0,
            AnalysisTermDimension::VisualCraft->value => 0,
        ];

        foreach ($rows as $row) {
            $dimension = $row->dimension instanceof AnalysisTermDimension
                ? $row->dimension->value
                : (string) $row->dimension;

            if (! array_key_exists($dimension, $result)) {
                continue;
            }

            if ($perDimension[$dimension] >= self::TOP_TERMS_PER_DIMENSION) {
                continue;
            }

            $slug = (string) $row->slug;

            $result[$dimension][] = [
                'slug' => $slug,
                'label' => (string) $row->label,
                'section' => $sections[$dimension.':'.$slug] ?? null,
                'count' => (int) $row->aggregate,
            ];
            $perDimension[$dimension]++;
        }

        return $result;
    }

    /**
     * @param  array<string, int>  $countsByDate
     * @return array{
     *     label: string,
     *     total: int,
     *     period_total: int,
     *     series: array<int, array{date: string, count: int}>,
     * }
     */
    private function buildMetricSeries(
        int $days,
        CarbonInterface $start,
        array $countsByDate,
        string $label,
        int $total,
    ): array {
        $series = [];
        $periodTotal = 0;

        for ($offset = 0; $offset < $days; $offset++) {
            $date = $start->copy()->addDays($offset)->toDateString();
            $count = (int) ($countsByDate[$date] ?? 0);
            $periodTotal += $count;

            $series[] = [
                'date' => $date,
                'count' => $count,
            ];
        }

        return [
            'label' => $label,
            'total' => $total,
            'period_total' => $periodTotal,
            'series' => $series,
        ];
    }

    /**
     * @param  array<string, int>  $countsByDate
     */
    private function sumCountsInRange(
        int $days,
        CarbonInterface $start,
        array $countsByDate,
    ): int {
        $periodTotal = 0;

        for ($offset = 0; $offset < $days; $offset++) {
            $date = $start->copy()->addDays($offset)->toDateString();
            $periodTotal += (int) ($countsByDate[$date] ?? 0);
        }

        return $periodTotal;
    }
}
