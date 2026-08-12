<?php

namespace App\Services\Admin;

use App\Enums\AnalysisStatus;
use App\Enums\BillingVendor;
use App\Models\CreditLedgerEntry;
use App\Models\McpToolInvocation;
use App\Models\Post;
use App\Models\PostAnalysis;
use App\Models\TrackedAccount;
use App\Models\User;
use App\Services\Billing\UsageBillingService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class AdminOverviewService
{
    public function __construct(private UsageBillingService $billing) {}

    /**
     * @return array<string, mixed>
     */
    public function overview(string $grain = 'day', ?int $periods = null): array
    {
        $grain = in_array($grain, ['day', 'week', 'month'], true) ? $grain : 'day';

        $periodCount = match ($grain) {
            'week' => max(4, min(26, $periods ?? 12)),
            'month' => max(3, min(24, $periods ?? 12)),
            default => max(7, min(90, $periods ?? 30)),
        };

        $to = now()->endOfDay();
        $from = match ($grain) {
            'week' => now()->startOfWeek()->subWeeks($periodCount - 1)->startOfDay(),
            'month' => now()->startOfMonth()->subMonthsNoOverflow($periodCount - 1)->startOfDay(),
            default => now()->subDays($periodCount - 1)->startOfDay(),
        };

        return [
            'grain' => $grain,
            'period_count' => $periodCount,
            'days' => (int) $from->diffInDays($to) + 1,
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'kpis' => $this->kpis($from),
            'usersSeries' => $this->usersSeries($grain, $from, $to, $periodCount),
            'spendSeries' => $this->globalSpendSeries($grain, $from, $to, $periodCount),
            'profit' => $this->profit($from, $to, $grain, $periodCount),
            'platforms' => $this->platforms(),
            'analysisStatus' => $this->analysisStatusMix(),
            'failedAnalyses' => $this->failedAnalyses(),
            'mcpTools' => $this->mcpTools($from, $to),
            'topActions' => $this->topActions($from, $to),
            'syncFailures' => $this->syncFailures(),
            'creditExpirySeries' => $this->creditExpirySeries(),
        ];
    }

    /**
     * Platform-wide unused credit scheduled to expire by calendar month.
     *
     * @return array{
     *     months: int,
     *     from: string,
     *     to: string,
     *     never_pence: float,
     *     total_scheduled_pence: float,
     *     points: list<array{month: string, label: string, remaining_pence: float}>
     * }
     */
    public function creditExpirySeries(int $months = 12): array
    {
        $months = max(3, min(24, $months));
        $from = now()->startOfMonth();
        $to = $from->copy()->addMonthsNoOverflow($months - 1)->endOfMonth();

        $points = [];
        for ($offset = 0; $offset < $months; $offset++) {
            $cursor = $from->copy()->addMonthsNoOverflow($offset);
            $points[$cursor->format('Y-m')] = [
                'month' => $cursor->format('Y-m'),
                'label' => $cursor->format('M Y'),
                'remaining_pence' => 0.0,
            ];
        }

        $neverPence = 0.0;
        $scheduledTotal = 0.0;

        $lots = CreditLedgerEntry::query()
            ->where('amount_pence', '>', 0)
            ->where('remaining_pence', '>', 0)
            ->where(function ($query): void {
                $query->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            })
            ->get(['remaining_pence', 'expires_at']);

        foreach ($lots as $lot) {
            $remaining = $this->roundPence((float) ($lot->remaining_pence ?? 0));
            if ($remaining <= 0) {
                continue;
            }

            if ($lot->expires_at === null) {
                $neverPence = $this->roundPence($neverPence + $remaining);

                continue;
            }

            if ($lot->expires_at->greaterThan($to)) {
                continue;
            }

            $monthKey = $lot->expires_at->format('Y-m');
            if (! isset($points[$monthKey])) {
                continue;
            }

            $points[$monthKey]['remaining_pence'] = $this->roundPence(
                $points[$monthKey]['remaining_pence'] + $remaining,
            );
            $scheduledTotal = $this->roundPence($scheduledTotal + $remaining);
        }

        return [
            'months' => $months,
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'never_pence' => $neverPence,
            'total_scheduled_pence' => $scheduledTotal,
            'points' => array_values($points),
        ];
    }

    /**
     * @return array{
     *     users_total: int,
     *     users_new: int,
     *     subscribed: int,
     *     balance_pence: float,
     *     period_spend_pence: float,
     *     all_time_spend_pence: float,
     *     mcp_calls: int,
     *     failed_analyses: int
     * }
     */
    private function kpis(\DateTimeInterface $from): array
    {
        $vendorKeys = UsageBillingService::spendVendorKeys();

        $periodSpend = (float) CreditLedgerEntry::query()
            ->where('amount_pence', '<', 0)
            ->whereIn('vendor', $vendorKeys)
            ->where('created_at', '>=', $from)
            ->sum(DB::raw('ABS(amount_pence)'));

        $allTimeSpend = (float) CreditLedgerEntry::query()
            ->where('amount_pence', '<', 0)
            ->whereIn('vendor', $vendorKeys)
            ->sum(DB::raw('ABS(amount_pence)'));

        $balance = (float) User::query()->get()->sum(
            fn (User $user): float => $this->billing->balancePence($user),
        );

        return [
            'users_total' => User::query()->count(),
            'users_new' => User::query()->where('created_at', '>=', $from)->count(),
            'subscribed' => User::query()->whereHas('subscriptions', function ($query): void {
                $query->whereIn('stripe_status', ['active', 'trialing']);
            })->count(),
            'balance_pence' => $this->roundPence($balance),
            'period_spend_pence' => $this->roundPence($periodSpend),
            'all_time_spend_pence' => $this->roundPence($allTimeSpend),
            'mcp_calls' => McpToolInvocation::query()->where('created_at', '>=', $from)->count(),
            'failed_analyses' => PostAnalysis::query()
                ->where('status', AnalysisStatus::Failed)
                ->count(),
        ];
    }

    /**
     * @return list<array{week_start: string, label: string, count: int}>
     */
    private function usersSeries(string $grain, \DateTimeInterface $from, \DateTimeInterface $to, int $periodCount): array
    {
        $buckets = $this->emptyCountBuckets($grain, $from, $periodCount);

        $rows = User::query()
            ->where('created_at', '>=', $from)
            ->where('created_at', '<=', $to)
            ->get(['created_at']);

        foreach ($rows as $row) {
            if ($row->created_at === null) {
                continue;
            }
            $key = $this->bucketKey($grain, $row->created_at);
            if (isset($buckets[$key])) {
                $buckets[$key]['count']++;
            }
        }

        return array_values(array_map(fn (array $b): array => [
            'week_start' => $b['date'],
            'label' => $b['label'],
            'count' => $b['count'],
        ], $buckets));
    }

    /**
     * @return array{
     *     grain: string,
     *     period_count: int,
     *     days: int,
     *     from: string,
     *     to: string,
     *     points: list<array{date: string, label: string, apify: float, nanogpt: float, firecrawl: float, tikhub: float, snitch: float, total: float}>
     * }
     */
    private function globalSpendSeries(string $grain, \DateTimeInterface $from, \DateTimeInterface $to, int $periodCount): array
    {
        $vendorKeys = UsageBillingService::spendVendorKeys();
        $buckets = [];

        for ($offset = 0; $offset < $periodCount; $offset++) {
            $cursor = match ($grain) {
                'week' => Carbon::parse($from)->copy()->addWeeks($offset)->startOfWeek(),
                'month' => Carbon::parse($from)->copy()->addMonthsNoOverflow($offset)->startOfMonth(),
                default => Carbon::parse($from)->copy()->addDays($offset)->startOfDay(),
            };
            $key = $cursor->toDateString();
            $buckets[$key] = [
                'date' => $key,
                'label' => match ($grain) {
                    'week' => $cursor->format('j M'),
                    'month' => $cursor->format('M Y'),
                    default => $cursor->format('j M'),
                },
                'apify' => 0.0,
                'nanogpt' => 0.0,
                'firecrawl' => 0.0,
                'tikhub' => 0.0,
                'snitch' => 0.0,
                'total' => 0.0,
            ];
        }

        $entries = CreditLedgerEntry::query()
            ->where('amount_pence', '<', 0)
            ->whereIn('vendor', $vendorKeys)
            ->where('created_at', '>=', $from)
            ->where('created_at', '<=', $to)
            ->get(['vendor', 'amount_pence', 'created_at']);

        foreach ($entries as $entry) {
            if ($entry->created_at === null) {
                continue;
            }

            $key = $this->bucketKey($grain, $entry->created_at);
            if (! isset($buckets[$key])) {
                continue;
            }

            $vendor = $entry->vendor instanceof BillingVendor
                ? $entry->vendor->value
                : (string) $entry->vendor;

            if (! array_key_exists($vendor, $buckets[$key])) {
                continue;
            }

            $pence = abs($this->roundPence((float) $entry->amount_pence));
            $buckets[$key][$vendor] = $this->roundPence($buckets[$key][$vendor] + $pence);
            $buckets[$key]['total'] = $this->roundPence($buckets[$key]['total'] + $pence);
        }

        return [
            'grain' => $grain,
            'period_count' => $periodCount,
            'days' => (int) Carbon::parse($from)->diffInDays($to) + 1,
            'from' => Carbon::parse($from)->toDateString(),
            'to' => Carbon::parse($to)->toDateString(),
            'points' => array_values($buckets),
        ];
    }

    /**
     * @return array{
     *     charged_gbp: float,
     *     cogs_gbp: float,
     *     margin_gbp: float,
     *     margin_pct: float|null,
     *     points: list<array{date: string, label: string, charged_gbp: float, cogs_gbp: float, margin_gbp: float}>
     * }
     */
    private function profit(\DateTimeInterface $from, \DateTimeInterface $to, string $grain, int $periodCount): array
    {
        $usdToGbp = (float) config('billing.usd_to_gbp', 0.79);
        $buckets = $this->emptyCountBuckets($grain, $from, $periodCount);
        foreach ($buckets as $key => $bucket) {
            $buckets[$key] = [
                'date' => $bucket['date'],
                'label' => $bucket['label'],
                'charged_gbp' => 0.0,
                'cogs_gbp' => 0.0,
                'margin_gbp' => 0.0,
            ];
        }

        $entries = CreditLedgerEntry::query()
            ->where('amount_pence', '<', 0)
            ->whereIn('vendor', UsageBillingService::spendVendorKeys())
            ->where('created_at', '>=', $from)
            ->where('created_at', '<=', $to)
            ->get(['amount_pence', 'cogs_usd', 'created_at']);

        $chargedGbp = 0.0;
        $cogsGbp = 0.0;

        foreach ($entries as $entry) {
            if ($entry->created_at === null) {
                continue;
            }

            $charge = abs((float) $entry->amount_pence) / 100;
            $cogs = $entry->cogs_usd !== null
                ? max(0.0, (float) $entry->cogs_usd) * $usdToGbp
                : 0.0;

            $chargedGbp += $charge;
            $cogsGbp += $cogs;

            $key = $this->bucketKey($grain, $entry->created_at);
            if (! isset($buckets[$key])) {
                continue;
            }

            $buckets[$key]['charged_gbp'] += $charge;
            $buckets[$key]['cogs_gbp'] += $cogs;
            $buckets[$key]['margin_gbp'] = $buckets[$key]['charged_gbp'] - $buckets[$key]['cogs_gbp'];
        }

        foreach ($buckets as $key => $bucket) {
            $buckets[$key]['charged_gbp'] = round($bucket['charged_gbp'], 4);
            $buckets[$key]['cogs_gbp'] = round($bucket['cogs_gbp'], 4);
            $buckets[$key]['margin_gbp'] = round($bucket['margin_gbp'], 4);
        }

        $margin = $chargedGbp - $cogsGbp;

        return [
            'charged_gbp' => round($chargedGbp, 4),
            'cogs_gbp' => round($cogsGbp, 4),
            'margin_gbp' => round($margin, 4),
            'margin_pct' => $chargedGbp > 0 ? round(($margin / $chargedGbp) * 100, 1) : null,
            'points' => array_values($buckets),
        ];
    }

    /**
     * @return list<array{platform: string, count: int}>
     */
    private function platforms(): array
    {
        return Post::query()
            ->selectRaw('platform, COUNT(*) as aggregate')
            ->groupBy('platform')
            ->orderByDesc('aggregate')
            ->get()
            ->map(function ($row): array {
                $platform = $row->platform;

                return [
                    'platform' => $platform instanceof \BackedEnum
                        ? $platform->value
                        : (string) $platform,
                    'count' => (int) $row->aggregate,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return list<array{status: string, count: int}>
     */
    private function analysisStatusMix(): array
    {
        return PostAnalysis::query()
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->orderByDesc('aggregate')
            ->get()
            ->map(fn ($row): array => [
                'status' => $row->status instanceof AnalysisStatus
                    ? $row->status->value
                    : (string) $row->status,
                'count' => (int) $row->aggregate,
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array{id: int, post_id: int|null, platform: string|null, error_message: string|null, analyzed_at: string|null, created_at: string|null}>
     */
    private function failedAnalyses(): array
    {
        return PostAnalysis::query()
            ->with(['post:id,platform'])
            ->where('status', AnalysisStatus::Failed)
            ->orderByDesc('id')
            ->limit(25)
            ->get()
            ->map(fn (PostAnalysis $analysis): array => [
                'id' => $analysis->id,
                'post_id' => $analysis->post_id,
                'platform' => $analysis->post?->platform?->value ?? (is_string($analysis->post?->platform) ? $analysis->post->platform : null),
                'error_message' => $analysis->error_message !== null
                    ? mb_substr((string) $analysis->error_message, 0, 180)
                    : null,
                'analyzed_at' => $analysis->analyzed_at?->toIso8601String(),
                'created_at' => $analysis->created_at?->toIso8601String(),
            ])
            ->all();
    }

    /**
     * @return array{
     *     total: int,
     *     ok: int,
     *     errors: int,
     *     tools: list<array{tool: string, count: int, ok: int, errors: int}>
     * }
     */
    private function mcpTools(\DateTimeInterface $from, \DateTimeInterface $to): array
    {
        $rows = McpToolInvocation::query()
            ->where('created_at', '>=', $from)
            ->where('created_at', '<=', $to)
            ->selectRaw('tool, COUNT(*) as aggregate, SUM(CASE WHEN ok THEN 1 ELSE 0 END) as ok_count')
            ->groupBy('tool')
            ->orderByDesc('aggregate')
            ->limit(40)
            ->get();

        $tools = $rows->map(function ($row): array {
            $count = (int) $row->aggregate;
            $ok = (int) $row->ok_count;

            return [
                'tool' => (string) $row->tool,
                'count' => $count,
                'ok' => $ok,
                'errors' => max(0, $count - $ok),
            ];
        })->values()->all();

        $total = array_sum(array_column($tools, 'count'));
        $ok = array_sum(array_column($tools, 'ok'));

        return [
            'total' => $total,
            'ok' => $ok,
            'errors' => max(0, $total - $ok),
            'tools' => $tools,
        ];
    }

    /**
     * @return list<array{action: string, count: int, spend_pence: float}>
     */
    private function topActions(\DateTimeInterface $from, \DateTimeInterface $to): array
    {
        return CreditLedgerEntry::query()
            ->where('amount_pence', '<', 0)
            ->where('created_at', '>=', $from)
            ->where('created_at', '<=', $to)
            ->selectRaw('action, COUNT(*) as aggregate, SUM(ABS(amount_pence)) as spend_pence')
            ->groupBy('action')
            ->orderByDesc('aggregate')
            ->limit(12)
            ->get()
            ->map(fn ($row): array => [
                'action' => (string) $row->action,
                'count' => (int) $row->aggregate,
                'spend_pence' => $this->roundPence((float) $row->spend_pence),
            ])
            ->all();
    }

    /**
     * @return list<array{id: int, handle: string, platform: string, last_sync_error: string|null, last_synced_at: string|null}>
     */
    private function syncFailures(): array
    {
        return TrackedAccount::query()
            ->where('last_sync_status', 'failed')
            ->orderByDesc('updated_at')
            ->limit(15)
            ->get(['id', 'handle', 'platform', 'last_sync_error', 'last_synced_at'])
            ->map(fn (TrackedAccount $account): array => [
                'id' => $account->id,
                'handle' => (string) $account->handle,
                'platform' => $account->platform instanceof \BackedEnum
                    ? $account->platform->value
                    : (string) $account->platform,
                'last_sync_error' => $account->last_sync_error !== null
                    ? mb_substr((string) $account->last_sync_error, 0, 160)
                    : null,
                'last_synced_at' => $account->last_synced_at?->toIso8601String(),
            ])
            ->all();
    }

    /**
     * @return array<string, array{date: string, label: string, count: int}>
     */
    private function emptyCountBuckets(string $grain, \DateTimeInterface $from, int $periodCount): array
    {
        $buckets = [];
        $start = Carbon::parse($from);

        for ($offset = 0; $offset < $periodCount; $offset++) {
            $cursor = match ($grain) {
                'week' => $start->copy()->addWeeks($offset)->startOfWeek(),
                'month' => $start->copy()->addMonthsNoOverflow($offset)->startOfMonth(),
                default => $start->copy()->addDays($offset)->startOfDay(),
            };
            $key = $cursor->toDateString();
            $buckets[$key] = [
                'date' => $key,
                'label' => match ($grain) {
                    'week' => $cursor->format('j M'),
                    'month' => $cursor->format('M Y'),
                    default => $cursor->format('j M'),
                },
                'count' => 0,
            ];
        }

        return $buckets;
    }

    private function bucketKey(string $grain, \DateTimeInterface $at): string
    {
        $carbon = Carbon::parse($at);

        return match ($grain) {
            'week' => $carbon->copy()->startOfWeek()->toDateString(),
            'month' => $carbon->copy()->startOfMonth()->toDateString(),
            default => $carbon->toDateString(),
        };
    }

    private function roundPence(float $pence): float
    {
        return round($pence, 2, PHP_ROUND_HALF_UP);
    }
}
