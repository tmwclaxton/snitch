<?php

namespace App\Services\Admin;

use App\Models\CreditLedgerEntry;
use App\Models\McpToolInvocation;
use App\Models\PostAnalysis;
use App\Models\User;
use App\Services\Billing\LedgerChargePresenter;
use App\Services\Billing\UsageBillingService;
use Illuminate\Support\Facades\DB;

class AdminActivityService
{
    public function __construct(private LedgerChargePresenter $presenter) {}

    /**
     * @return array<string, mixed>
     */
    public function activity(string $grain = 'day', ?int $periods = null): array
    {
        $range = AdminPeriodRange::resolve($grain, $periods);

        return [
            ...$range->meta(),
            'kpis' => $this->kpis($range),
            'signupsSeries' => $this->signupsSeries($range),
            'ledgerSeries' => $this->ledgerSeries($range),
            'mcpSeries' => $this->mcpSeries($range),
            'analysesSeries' => $this->analysesSeries($range),
            'recentEvents' => $this->recentEvents(),
        ];
    }

    /**
     * @return array{
     *     signups: int,
     *     ledger_entries: int,
     *     ledger_spend_pence: float,
     *     mcp_calls: int,
     *     analyses: int
     * }
     */
    private function kpis(AdminPeriodRange $range): array
    {
        $vendorKeys = UsageBillingService::spendVendorKeys();

        $ledgerQuery = CreditLedgerEntry::query()
            ->where('created_at', '>=', $range->from)
            ->where('created_at', '<=', $range->to);

        $spendPence = (float) (clone $ledgerQuery)
            ->where('amount_pence', '<', 0)
            ->whereIn('vendor', $vendorKeys)
            ->sum(DB::raw('ABS(amount_pence)'));

        return [
            'signups' => User::query()
                ->where('created_at', '>=', $range->from)
                ->where('created_at', '<=', $range->to)
                ->count(),
            'ledger_entries' => (clone $ledgerQuery)->count(),
            'ledger_spend_pence' => $this->roundPence($spendPence),
            'mcp_calls' => McpToolInvocation::query()
                ->where('created_at', '>=', $range->from)
                ->where('created_at', '<=', $range->to)
                ->count(),
            'analyses' => PostAnalysis::query()
                ->where('created_at', '>=', $range->from)
                ->where('created_at', '<=', $range->to)
                ->count(),
        ];
    }

    /**
     * @return list<array{week_start: string, label: string, count: int}>
     */
    private function signupsSeries(AdminPeriodRange $range): array
    {
        $buckets = $range->emptyCountBuckets();

        $rows = User::query()
            ->where('created_at', '>=', $range->from)
            ->where('created_at', '<=', $range->to)
            ->get(['created_at']);

        foreach ($rows as $row) {
            if ($row->created_at === null) {
                continue;
            }

            $key = $range->bucketKey($row->created_at);
            if (isset($buckets[$key])) {
                $buckets[$key]['count']++;
            }
        }

        return $range->countSeries($buckets);
    }

    /**
     * @return list<array{week_start: string, label: string, count: int}>
     */
    private function ledgerSeries(AdminPeriodRange $range): array
    {
        $buckets = $range->emptyCountBuckets();

        $rows = CreditLedgerEntry::query()
            ->where('created_at', '>=', $range->from)
            ->where('created_at', '<=', $range->to)
            ->get(['created_at']);

        foreach ($rows as $row) {
            if ($row->created_at === null) {
                continue;
            }

            $key = $range->bucketKey($row->created_at);
            if (isset($buckets[$key])) {
                $buckets[$key]['count']++;
            }
        }

        return $range->countSeries($buckets);
    }

    /**
     * @return list<array{week_start: string, label: string, count: int}>
     */
    private function mcpSeries(AdminPeriodRange $range): array
    {
        $buckets = $range->emptyCountBuckets();

        $rows = McpToolInvocation::query()
            ->where('created_at', '>=', $range->from)
            ->where('created_at', '<=', $range->to)
            ->get(['created_at']);

        foreach ($rows as $row) {
            if ($row->created_at === null) {
                continue;
            }

            $key = $range->bucketKey($row->created_at);
            if (isset($buckets[$key])) {
                $buckets[$key]['count']++;
            }
        }

        return $range->countSeries($buckets);
    }

    /**
     * @return list<array{week_start: string, label: string, count: int}>
     */
    private function analysesSeries(AdminPeriodRange $range): array
    {
        $buckets = $range->emptyCountBuckets();

        $rows = PostAnalysis::query()
            ->where('created_at', '>=', $range->from)
            ->where('created_at', '<=', $range->to)
            ->get(['created_at']);

        foreach ($rows as $row) {
            if ($row->created_at === null) {
                continue;
            }

            $key = $range->bucketKey($row->created_at);
            if (isset($buckets[$key])) {
                $buckets[$key]['count']++;
            }
        }

        return $range->countSeries($buckets);
    }

    /**
     * Unified platform activity from existing first-party tables (no separate audit bus).
     *
     * @return list<array{
     *     id: string,
     *     type: string,
     *     summary: string,
     *     occurred_at: string,
     *     user_id: int|null,
     *     user_email: string|null,
     *     ok: bool|null
     * }>
     */
    private function recentEvents(int $limit = 50): array
    {
        $events = [];

        CreditLedgerEntry::query()
            ->with('user:id,email')
            ->orderByDesc('id')
            ->limit(25)
            ->get()
            ->each(function (CreditLedgerEntry $entry) use (&$events): void {
                $presented = $this->presenter->present(
                    $entry->action,
                    is_array($entry->meta) ? $entry->meta : null,
                );

                $events[] = [
                    'id' => 'ledger-'.$entry->id,
                    'type' => 'ledger',
                    'summary' => $presented['description'],
                    'occurred_at' => $entry->created_at?->toIso8601String() ?? now()->toIso8601String(),
                    'user_id' => $entry->user_id,
                    'user_email' => $entry->user?->email,
                    'ok' => (float) $entry->amount_pence >= 0 ? true : null,
                ];
            });

        McpToolInvocation::query()
            ->with('user:id,email')
            ->orderByDesc('id')
            ->limit(25)
            ->get()
            ->each(function (McpToolInvocation $invocation) use (&$events): void {
                $events[] = [
                    'id' => 'mcp-'.$invocation->id,
                    'type' => 'mcp',
                    'summary' => $invocation->tool,
                    'occurred_at' => $invocation->created_at?->toIso8601String() ?? now()->toIso8601String(),
                    'user_id' => $invocation->user_id,
                    'user_email' => $invocation->user?->email,
                    'ok' => $invocation->ok,
                ];
            });

        User::query()
            ->orderByDesc('id')
            ->limit(15)
            ->get(['id', 'email', 'created_at'])
            ->each(function (User $user) use (&$events): void {
                $events[] = [
                    'id' => 'signup-'.$user->id,
                    'type' => 'signup',
                    'summary' => 'New account',
                    'occurred_at' => $user->created_at?->toIso8601String() ?? now()->toIso8601String(),
                    'user_id' => $user->id,
                    'user_email' => $user->email,
                    'ok' => true,
                ];
            });

        PostAnalysis::query()
            ->with(['post:id,platform'])
            ->orderByDesc('id')
            ->limit(15)
            ->get()
            ->each(function (PostAnalysis $analysis) use (&$events): void {
                $platform = $analysis->post?->platform;
                $platformLabel = $platform instanceof \BackedEnum
                    ? $platform->value
                    : (is_string($platform) ? $platform : 'post');
                $status = $analysis->status instanceof \BackedEnum
                    ? $analysis->status->value
                    : (string) $analysis->status;

                $events[] = [
                    'id' => 'analysis-'.$analysis->id,
                    'type' => 'analysis',
                    'summary' => 'Analysis · '.$platformLabel.' · '.$status,
                    'occurred_at' => ($analysis->analyzed_at ?? $analysis->created_at)?->toIso8601String() ?? now()->toIso8601String(),
                    'user_id' => null,
                    'user_email' => null,
                    'ok' => $status !== 'failed',
                ];
            });

        usort($events, function (array $a, array $b): int {
            return strcmp($b['occurred_at'], $a['occurred_at']);
        });

        return array_slice($events, 0, $limit);
    }

    private function roundPence(float $pence): float
    {
        return round($pence, 2, PHP_ROUND_HALF_UP);
    }
}
