<?php

namespace App\Services\Billing;

use App\Enums\BillingVendor;
use App\Exceptions\InsufficientCreditsException;
use App\Exceptions\PlatformSubscriptionRequiredException;
use App\Models\CreditBalance;
use App\Models\CreditLedgerEntry;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class UsageBillingService
{
    public const RECENT_PREVIEW_LIMIT = 8;

    public const CHARGES_PER_PAGE = 25;

    public function __construct(
        private LedgerChargePresenter $presenter,
    ) {}

    public function balancePence(User $user): float
    {
        $this->syncExpiredLots($user);

        return $this->roundPence((float) ($this->balanceRow($user)->balance_pence ?? 0));
    }

    public function hasPlatformSubscription(User $user): bool
    {
        $type = (string) config('billing.subscription_type', 'default');
        $subscription = $user->subscription($type);

        return $subscription !== null && $subscription->valid();
    }

    public function minRunBalancePence(): int
    {
        return max(0, (int) config('billing.min_run_balance_pence', 20));
    }

    public function starterAllowanceExhausted(User $user): bool
    {
        return (bool) $this->balanceRow($user)->starter_allowance_exhausted;
    }

    public function markStarterAllowanceExhausted(User $user): void
    {
        $row = $this->balanceRow($user);

        if ($row->starter_allowance_exhausted) {
            return;
        }

        $row->forceFill(['starter_allowance_exhausted' => true])->save();
    }

    /**
     * Balance that counts for product access. Unsubscribed users may only spend
     * never-expiring claim_bonus (starter £5). Subscribed users use all unexpired lots.
     */
    public function accessibleBalancePence(User $user): float
    {
        $this->syncExpiredLots($user);

        if ($this->hasPlatformSubscription($user)) {
            return $this->sumUnexpiredRemaining($user);
        }

        return $this->claimBonusRemainingPence($user);
    }

    public function claimBonusRemainingPence(User $user): float
    {
        return $this->roundPence((float) CreditLedgerEntry::query()
            ->where('user_id', $user->id)
            ->where('action', 'claim_bonus')
            ->where('amount_pence', '>', 0)
            ->where(function ($query): void {
                $query->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            })
            ->sum(DB::raw('COALESCE(remaining_pence, 0)')));
    }

    /**
     * @return array{
     *     blocked: bool,
     *     reason: 'subscribe'|'credits'|null,
     *     message: string|null,
     *     starter_allowance_exhausted: bool,
     *     can_top_up: bool
     * }
     */
    public function paywallState(User $user): array
    {
        $subscribed = $this->hasPlatformSubscription($user);
        $starterExhausted = $this->starterAllowanceExhausted($user);
        $accessible = $this->accessibleBalancePence($user);
        $minExclusive = $this->minRunBalancePence();
        $hasBalance = $accessible > $minExclusive;

        if ($subscribed) {
            if ($hasBalance) {
                return [
                    'blocked' => false,
                    'reason' => null,
                    'message' => null,
                    'starter_allowance_exhausted' => $starterExhausted,
                    'can_top_up' => true,
                ];
            }

            return [
                'blocked' => true,
                'reason' => 'credits',
                'message' => 'This month\'s usage allowance is spent. Top up credits on the Billing page to continue.',
                'starter_allowance_exhausted' => $starterExhausted,
                'can_top_up' => true,
            ];
        }

        if (! $starterExhausted && $hasBalance) {
            return [
                'blocked' => false,
                'reason' => null,
                'message' => null,
                'starter_allowance_exhausted' => false,
                'can_top_up' => false,
            ];
        }

        if (! $hasBalance) {
            $this->markStarterAllowanceExhausted($user);
        }

        return [
            'blocked' => true,
            'reason' => 'subscribe',
            'message' => 'Your free £5 starter credit is used up. Subscribe to a paid plan on the Billing page to continue. Top-ups are available after you have a plan.',
            'starter_allowance_exhausted' => true,
            'can_top_up' => false,
        ];
    }

    public function canAccessProduct(User $user, float $estimatedPence = 1): bool
    {
        try {
            $this->assertCanAccessProduct($user, $estimatedPence);

            return true;
        } catch (InsufficientCreditsException|PlatformSubscriptionRequiredException) {
            return false;
        }
    }

    public function assertCanAccessProduct(User $user, float $estimatedPence = 1): void
    {
        $subscribed = $this->hasPlatformSubscription($user);
        $accessible = $this->accessibleBalancePence($user);
        $minExclusive = $this->minRunBalancePence();
        $estimate = max(0.01, $estimatedPence);
        $required = max($minExclusive + 1, $estimate);
        $hasBalance = $accessible > $minExclusive && $accessible >= $estimate;

        if (! $subscribed) {
            if ($this->starterAllowanceExhausted($user) || ! $hasBalance) {
                if (! $hasBalance) {
                    $this->markStarterAllowanceExhausted($user);
                }

                throw new PlatformSubscriptionRequiredException(
                    'Your free £5 starter credit is used up. Subscribe to a paid plan on the Billing page to continue. Top-ups are available after you have a plan.',
                );
            }

            return;
        }

        if (! $hasBalance) {
            throw new InsufficientCreditsException(
                requiredPence: $required,
                balancePence: $accessible,
                message: sprintf(
                    'Your balance must be more than %dp to continue. This month\'s usage allowance is spent - top up credits on the Billing page.',
                    $minExclusive,
                ),
            );
        }
    }

    public function assertCanTopUp(User $user): void
    {
        if (! $this->hasPlatformSubscription($user)) {
            throw new PlatformSubscriptionRequiredException(
                'Credit top-ups require an active paid plan. Subscribe on the Billing page first.',
            );
        }
    }

    public function canRun(User $user, float $estimatedPence = 1): bool
    {
        return $this->canAccessProduct($user, $estimatedPence);
    }

    public function assertCanRun(User $user, float $estimatedPence = 1): void
    {
        $this->assertCanAccessProduct($user, $estimatedPence);
    }

    /**
     * Charge the user for vendor usage. amount_pence is negative on the ledger.
     * Returns null when the priced amount rounds to £0 so we do not write noise
     * rows or burn Apify run idempotency keys on preliminary $0 usage.
     *
     * @param  array<string, mixed>  $meta
     */
    public function charge(
        User $user,
        string $action,
        BillingVendor|string $vendor,
        ?float $cogsUsd = null,
        array $meta = [],
        ?string $idempotencyKey = null,
        ?float $amountPenceOverride = null,
    ): ?CreditLedgerEntry {
        $vendorEnum = $vendor instanceof BillingVendor ? $vendor : BillingVendor::from($vendor);

        if (in_array($vendorEnum, [BillingVendor::Bonus, BillingVendor::Topup], true)) {
            throw new \InvalidArgumentException('Use credit helpers for bonus/top-up entries.');
        }

        $fixedPence = $amountPenceOverride !== null
            ? $this->roundPence(max(0.0, $amountPenceOverride))
            : $this->fixedPenceForAction($action);
        $amountPence = $this->roundPence($fixedPence ?? $this->pricePenceFromCogs($action, $vendorEnum, $cogsUsd));

        // No minimum charge, but a £0 debit is not useful audit - skip the write.
        if ($amountPence === 0.0) {
            return null;
        }

        $this->assertCanAccessProduct($user, max(0.01, abs($amountPence)));

        $multiplier = $fixedPence !== null
            ? null
            : (float) config('billing.price_multiplier', 1.75);
        $cogsForEntry = $fixedPence !== null ? null : $cogsUsd;

        return $this->writeEntry(
            user: $user,
            action: $action,
            vendor: $vendorEnum,
            amountPence: -abs($amountPence),
            cogsUsd: $cogsForEntry,
            multiplier: $multiplier,
            meta: $meta,
            idempotencyKey: $idempotencyKey,
            requirePlatform: false,
            requireCredits: true,
        );
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    public function creditFromTopUp(
        User $user,
        int $creditsPence,
        string $idempotencyKey,
        array $meta = [],
    ): CreditLedgerEntry {
        return $this->writeEntry(
            user: $user,
            action: 'credits.topup',
            vendor: BillingVendor::Topup,
            amountPence: abs($creditsPence),
            cogsUsd: null,
            multiplier: null,
            meta: $meta,
            idempotencyKey: $idempotencyKey,
            requirePlatform: false,
            requireCredits: false,
            expiresAt: now()->addMonthsNoOverflow(max(1, (int) config('billing.topup_expiry_months', 3))),
        );
    }

    public function creditClaimBonus(User $user): ?CreditLedgerEntry
    {
        $bonus = max(0, (int) config('billing.claim_bonus_pence', 500));

        if ($bonus <= 0) {
            return null;
        }

        return $this->writeEntry(
            user: $user,
            action: 'claim_bonus',
            vendor: BillingVendor::Bonus,
            amountPence: $bonus,
            cogsUsd: null,
            multiplier: null,
            meta: [],
            idempotencyKey: 'claim_bonus:'.$user->id,
            requirePlatform: false,
            requireCredits: false,
            expiresAt: null,
        );
    }

    /**
     * Grant platform subscription usage credits (idempotent per Stripe invoice).
     *
     * @param  array<string, mixed>  $meta
     */
    public function creditSubscriptionBonus(
        User $user,
        string $idempotencyKey,
        array $meta = [],
    ): ?CreditLedgerEntry {
        $bonus = max(0, (int) config('billing.subscription_bonus_pence', 3000));

        if ($bonus <= 0) {
            return null;
        }

        return $this->writeEntry(
            user: $user,
            action: 'subscription_bonus',
            vendor: BillingVendor::Bonus,
            amountPence: $bonus,
            cogsUsd: null,
            multiplier: null,
            meta: $meta,
            idempotencyKey: $idempotencyKey,
            requirePlatform: false,
            requireCredits: false,
            expiresAt: now()->endOfMonth(),
        );
    }

    public function estimatePence(string $action, BillingVendor|string $vendor, ?float $cogsUsd = null): float
    {
        $fixedPence = $this->fixedPenceForAction($action);
        if ($fixedPence !== null) {
            return $fixedPence;
        }

        $vendorEnum = $vendor instanceof BillingVendor ? $vendor : BillingVendor::from($vendor);

        return $this->pricePenceFromCogs($action, $vendorEnum, $cogsUsd);
    }

    /**
     * Vendors shown in period usage totals and the stacked spend chart
     * (COGS vendors plus Snitch product fees).
     *
     * @return list<string>
     */
    public static function spendVendorKeys(): array
    {
        return [
            BillingVendor::Apify->value,
            BillingVendor::NanoGpt->value,
            BillingVendor::Firecrawl->value,
            BillingVendor::TikHub->value,
            BillingVendor::Snitch->value,
        ];
    }

    /**
     * Platform-wide mean charge per ledger run for each spend vendor.
     * Same rollup shape as billing {@see summary()} vendor totals
     * (spend_pence / entries), but across all users.
     *
     * @return list<array{
     *     vendor: string,
     *     avg_pence: float,
     *     spend_pence: float,
     *     entries: int
     * }>
     */
    public function globalVendorAverages(): array
    {
        $ttl = max(60, (int) config('billing.global_averages_cache_seconds', 300));

        /** @var list<array{vendor: string, avg_pence: float, spend_pence: float, entries: int}> */
        return Cache::remember('billing.global_vendor_averages', $ttl, function (): array {
            $vendorKeys = self::spendVendorKeys();

            /** @var array<string, array{vendor: string, avg_pence: float, spend_pence: float, entries: int}> $averages */
            $averages = [];
            foreach ($vendorKeys as $key) {
                $averages[$key] = [
                    'vendor' => $key,
                    'avg_pence' => 0.0,
                    'spend_pence' => 0.0,
                    'entries' => 0,
                ];
            }

            $rows = CreditLedgerEntry::query()
                ->where('amount_pence', '<', 0)
                ->whereIn('vendor', $vendorKeys)
                ->selectRaw('vendor, SUM(ABS(amount_pence)) as spend_pence, COUNT(*) as entries')
                ->groupBy('vendor')
                ->get();

            foreach ($rows as $row) {
                $key = $row->vendor instanceof BillingVendor
                    ? $row->vendor->value
                    : (string) $row->vendor;

                if (! array_key_exists($key, $averages)) {
                    continue;
                }

                $spend = $this->roundPence((float) $row->spend_pence);
                $entries = (int) $row->entries;
                $averages[$key] = [
                    'vendor' => $key,
                    'avg_pence' => $entries > 0 ? $this->roundPence($spend / $entries) : 0.0,
                    'spend_pence' => $spend,
                    'entries' => $entries,
                ];
            }

            return array_values($averages);
        });
    }

    /**
     * Daily charged usage (Apify / NanoGPT / Firecrawl / TikHub / Snitch) for stacked spend charts.
     *
     * @return array{
     *     grain: string,
     *     period_count: int,
     *     days: int,
     *     from: string,
     *     to: string,
     *     points: list<array{date: string, label: string, apify: float, nanogpt: float, firecrawl: float, tikhub: float, snitch: float, total: float}>
     * }
     */
    public function dailySpendSeries(User $user, int $days = 30): array
    {
        return $this->spendSeries($user, 'day', $days);
    }

    /**
     * Charged usage aggregated by day, week, or month for the billing spend chart.
     *
     * @param  'day'|'week'|'month'  $grain
     * @return array{
     *     grain: string,
     *     period_count: int,
     *     days: int,
     *     from: string,
     *     to: string,
     *     points: list<array{date: string, label: string, apify: float, nanogpt: float, firecrawl: float, tikhub: float, snitch: float, total: float}>
     * }
     */
    public function spendSeries(User $user, string $grain = 'day', ?int $periods = null): array
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

        $vendorKeys = self::spendVendorKeys();

        /** @var array<string, array{date: string, label: string, apify: float, nanogpt: float, firecrawl: float, tikhub: float, snitch: float, total: float}> $buckets */
        $buckets = [];

        for ($offset = 0; $offset < $periodCount; $offset++) {
            $cursor = match ($grain) {
                'week' => $from->copy()->addWeeks($offset)->startOfWeek(),
                'month' => $from->copy()->addMonthsNoOverflow($offset)->startOfMonth(),
                default => $from->copy()->addDays($offset)->startOfDay(),
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
            ->where('user_id', $user->id)
            ->where('amount_pence', '<', 0)
            ->whereIn('vendor', $vendorKeys)
            ->where('created_at', '>=', $from)
            ->where('created_at', '<=', $to)
            ->get(['vendor', 'amount_pence', 'created_at']);

        foreach ($entries as $entry) {
            if ($entry->created_at === null) {
                continue;
            }

            $key = match ($grain) {
                'week' => $entry->created_at->copy()->startOfWeek()->toDateString(),
                'month' => $entry->created_at->copy()->startOfMonth()->toDateString(),
                default => $entry->created_at->toDateString(),
            };

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
            'days' => (int) $from->diffInDays($to) + 1,
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'points' => array_values($buckets),
        ];
    }

    /**
     * @return array{
     *     balance_pence: float,
     *     subscribed: bool,
     *     platform_fee_pence: int,
     *     period: array{from: string, to: string},
     *     vendors: array<string, array{spend_pence: float, entries: int}>,
     *     period_spend_pence: float,
     *     all_time_spend_pence: float,
     *     recent: list<array<string, mixed>>,
     *     recent_total: int,
     *     recent_has_more: bool
     * }
     */
    public function summary(User $user, ?CarbonInterface $from = null, ?CarbonInterface $to = null): array
    {
        $from ??= now()->startOfMonth();
        $to ??= now();

        $vendorKeys = self::spendVendorKeys();

        $vendors = [];
        foreach ($vendorKeys as $key) {
            $vendors[$key] = ['spend_pence' => 0.0, 'entries' => 0];
        }

        $periodRows = CreditLedgerEntry::query()
            ->where('user_id', $user->id)
            ->where('amount_pence', '<', 0)
            ->whereBetween('created_at', [$from, $to])
            ->whereIn('vendor', $vendorKeys)
            ->selectRaw('vendor, SUM(ABS(amount_pence)) as spend_pence, COUNT(*) as entries')
            ->groupBy('vendor')
            ->get();

        foreach ($periodRows as $row) {
            $key = $row->vendor instanceof BillingVendor ? $row->vendor->value : (string) $row->vendor;
            $vendors[$key] = [
                'spend_pence' => $this->roundPence((float) $row->spend_pence),
                'entries' => (int) $row->entries,
            ];
        }

        $periodSpend = $this->roundPence(array_sum(array_column($vendors, 'spend_pence')));

        $allTimeSpend = $this->roundPence((float) CreditLedgerEntry::query()
            ->where('user_id', $user->id)
            ->where('amount_pence', '<', 0)
            ->whereIn('vendor', $vendorKeys)
            ->sum(DB::raw('ABS(amount_pence)')));

        $recentVendors = [
            ...$vendorKeys,
            BillingVendor::Bonus->value,
            BillingVendor::Topup->value,
        ];

        $recentQuery = CreditLedgerEntry::query()
            ->where('user_id', $user->id)
            ->whereIn('vendor', $recentVendors)
            ->where('amount_pence', '!=', 0);

        $recentTotal = (clone $recentQuery)->count();

        $recent = (clone $recentQuery)
            ->orderByDesc('id')
            ->limit(self::RECENT_PREVIEW_LIMIT)
            ->get()
            ->map(fn (CreditLedgerEntry $entry): array => $this->mapLedgerEntry($entry))
            ->all();

        return [
            'balance_pence' => $this->balancePence($user),
            'subscribed' => $this->hasPlatformSubscription($user),
            'platform_fee_pence' => (int) config('billing.platform_fee_pence', 1900),
            'period' => [
                'from' => $from->toIso8601String(),
                'to' => $to->toIso8601String(),
            ],
            'vendors' => $vendors,
            'period_spend_pence' => $periodSpend,
            'all_time_spend_pence' => $allTimeSpend,
            'recent' => $recent,
            'recent_total' => $recentTotal,
            'recent_has_more' => $recentTotal > self::RECENT_PREVIEW_LIMIT,
            'credit_expiry' => $this->creditExpiryBreakdown($user),
        ];
    }

    /**
     * Remaining credit lots grouped by expiry, soonest first (never-expiring last).
     *
     * @return array{
     *     total_remaining_pence: float,
     *     topup_expiry_months: int,
     *     buckets: list<array{
     *         expires_at: string|null,
     *         expires_label: string,
     *         remaining_pence: float,
     *         lots: list<array{
     *             action: string,
     *             label: string,
     *             remaining_pence: float
     *         }>
     *     }>
     * }
     */
    public function creditExpiryBreakdown(User $user): array
    {
        $this->syncExpiredLots($user);

        $lots = CreditLedgerEntry::query()
            ->where('user_id', $user->id)
            ->where('amount_pence', '>', 0)
            ->where('remaining_pence', '>', 0)
            ->where(function ($query): void {
                $query->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            })
            ->orderByRaw('CASE WHEN expires_at IS NULL THEN 1 ELSE 0 END')
            ->orderBy('expires_at')
            ->orderBy('id')
            ->get(['action', 'remaining_pence', 'expires_at']);

        /** @var array<string, array{expires_at: string|null, expires_label: string, remaining_pence: float, lots: list<array{action: string, label: string, remaining_pence: float}>}> $grouped */
        $grouped = [];

        foreach ($lots as $lot) {
            $remaining = $this->roundPence((float) ($lot->remaining_pence ?? 0));
            if ($remaining <= 0) {
                continue;
            }

            $bucketKey = $lot->expires_at === null
                ? '__never__'
                : $lot->expires_at->toIso8601String();

            if (! isset($grouped[$bucketKey])) {
                $grouped[$bucketKey] = [
                    'expires_at' => $lot->expires_at?->toIso8601String(),
                    'expires_label' => $this->creditExpiryLabel($lot->expires_at, $lot->action),
                    'remaining_pence' => 0.0,
                    'lots' => [],
                ];
            }

            $grouped[$bucketKey]['remaining_pence'] = $this->roundPence(
                $grouped[$bucketKey]['remaining_pence'] + $remaining,
            );
            $grouped[$bucketKey]['lots'][] = [
                'action' => $lot->action,
                'label' => $this->creditLotLabel($lot->action),
                'remaining_pence' => $remaining,
            ];
        }

        return [
            'total_remaining_pence' => $this->roundPence(array_sum(array_column($grouped, 'remaining_pence'))),
            'topup_expiry_months' => max(1, (int) config('billing.topup_expiry_months', 3)),
            'buckets' => array_values($grouped),
        ];
    }

    /**
     * Filter-aware expiry copy for the billing charges ledger.
     *
     * @return array{title: string, body: string}|null
     */
    public function creditExpiryFilterNote(?string $action): ?array
    {
        if (! is_string($action) || $action === '') {
            return null;
        }

        $months = max(1, (int) config('billing.topup_expiry_months', 3));

        return match ($action) {
            'claim_bonus' => [
                'title' => 'Starter credit',
                'body' => 'Welcome credits from claiming your account never expire.',
            ],
            'subscription_bonus' => [
                'title' => 'Plan credits',
                'body' => 'Subscription credits expire at the end of the calendar month they were granted. They do not roll over.',
            ],
            'credits.topup' => [
                'title' => 'Top-up credits',
                'body' => "Top-up credits expire {$months} months after purchase.",
            ],
            default => null,
        };
    }

    private function creditLotLabel(string $action): string
    {
        return match ($action) {
            'claim_bonus' => 'Starter credit',
            'subscription_bonus' => 'Plan credit',
            'credits.topup' => 'Top-up',
            default => Str::headline(str_replace('.', ' ', $action)),
        };
    }

    private function creditExpiryLabel(?CarbonInterface $expiresAt, string $action): string
    {
        if ($expiresAt === null) {
            return 'Never';
        }

        if ($action === 'subscription_bonus') {
            return 'End of '.$expiresAt->format('M Y');
        }

        return $expiresAt->format('j M Y');
    }

    /**
     * Paginated credit ledger for the billing charges breakdown page.
     *
     * @param  array{vendor?: string|null, action?: string|null, days?: int|null}  $filters
     * @return LengthAwarePaginator<int, array{
     *     id: int,
     *     action: string,
     *     vendor: string,
     *     amount_pence: float,
     *     balance_after_pence: float,
     *     created_at: string|null
     * }>
     */
    public function paginatedCharges(User $user, array $filters = [], int $perPage = self::CHARGES_PER_PAGE): LengthAwarePaginator
    {
        $query = CreditLedgerEntry::query()
            ->where('user_id', $user->id)
            ->where('amount_pence', '!=', 0)
            ->orderByDesc('id');

        $vendor = $filters['vendor'] ?? null;
        if (is_string($vendor) && $vendor !== '') {
            $query->where('vendor', $vendor);
        }

        $action = $filters['action'] ?? null;
        if (is_string($action) && $action !== '') {
            $query->where('action', $action);
        }

        $days = $filters['days'] ?? null;
        if (is_int($days) && $days > 0) {
            $query->where('created_at', '>=', now()->subDays($days)->startOfDay());
        }

        return $query
            ->paginate(max(1, min(100, $perPage)))
            ->withPath(route('billing.charges', absolute: false))
            ->withQueryString()
            ->through(fn (CreditLedgerEntry $entry): array => $this->mapLedgerEntry($entry, includeBalance: true));
    }

    /**
     * @return list<string>
     */
    public function ledgerActionOptions(): array
    {
        $configured = array_keys(config('billing.actions', []));

        return array_values(array_unique([
            ...$configured,
            'claim_bonus',
            'subscription_bonus',
            'credits.topup',
        ]));
    }

    /**
     * @return array{
     *     id: int,
     *     action: string,
     *     description: string,
     *     link: array{type: string, id?: int, label: string}|null,
     *     vendor: string,
     *     amount_pence: float,
     *     balance_after_pence?: float,
     *     created_at: string|null
     * }
     */
    private function mapLedgerEntry(CreditLedgerEntry $entry, bool $includeBalance = false): array
    {
        $presented = $this->presenter->present(
            $entry->action,
            is_array($entry->meta) ? $entry->meta : null,
        );

        $mapped = [
            'id' => $entry->id,
            'action' => $entry->action,
            'description' => $presented['description'],
            'link' => $presented['link'],
            'vendor' => $entry->vendor instanceof BillingVendor ? $entry->vendor->value : (string) $entry->vendor,
            'amount_pence' => $this->roundPence((float) $entry->amount_pence),
            'created_at' => $entry->created_at?->toIso8601String(),
        ];

        if ($includeBalance) {
            $mapped['balance_after_pence'] = $this->roundPence((float) $entry->balance_after_pence);
        }

        return $mapped;
    }

    public function pricePenceFromCogs(string $action, BillingVendor $vendor, ?float $cogsUsd): float
    {
        $fixedPence = $this->fixedPenceForAction($action);
        if ($fixedPence !== null) {
            return $fixedPence;
        }

        $floorUsd = (float) config("billing.actions.{$action}.floor_usd", config('billing.vendors.apify.floor_usd', 0.01));
        // Explicit 0 COGS charges 0; null falls back to catalog COGS for missing usage data.
        $cogs = $cogsUsd !== null ? max(0.0, $cogsUsd) : $floorUsd;
        $gbp = $cogs * (float) config('billing.usd_to_gbp', 0.79);
        $priced = $gbp * (float) config('billing.price_multiplier', 1.75);

        // Round half-up to 0.01p (£0.0001). No min charge / vendor ceil.
        return $this->roundPence($priced * 100);
    }

    /**
     * Product fees with an exact user charge (pence), bypassing COGS × markup.
     *
     * Action keys may contain dots (e.g. explore.search), so look up the map
     * by array key instead of dotted config paths.
     */
    public function fixedPenceForAction(string $action): ?float
    {
        $actions = config('billing.actions', []);
        if (! is_array($actions) || ! isset($actions[$action]) || ! is_array($actions[$action])) {
            return null;
        }

        $raw = $actions[$action]['fixed_pence'] ?? null;

        if ($raw === null || $raw === '') {
            return null;
        }

        if (! is_numeric($raw)) {
            return null;
        }

        return $this->roundPence(max(0.0, (float) $raw));
    }

    public function estimateNanoGptChatUsd(?int $inputTokens, ?int $outputTokens, string $model, string $floorKey = 'chat'): float
    {
        $floors = config('billing.vendors.nanogpt.floors_usd', []);
        $floor = is_array($floors) && isset($floors[$floorKey])
            ? (float) $floors[$floorKey]
            : 0.0005;

        // Look up by array key - model ids can contain dots (e.g. qwen3.7-flash),
        // which break Laravel dotted config("…models.{$model}") path access.
        $models = config('billing.vendors.nanogpt.models', []);
        $rates = is_array($models) ? ($models[$model] ?? null) : null;

        if (! is_array($rates) || ($inputTokens === null && $outputTokens === null)) {
            return $floor;
        }

        $in = max(0, (int) ($inputTokens ?? 0));
        $out = max(0, (int) ($outputTokens ?? 0));

        return ($in / 1_000_000) * (float) ($rates['input_per_m_usd'] ?? 0)
            + ($out / 1_000_000) * (float) ($rates['output_per_m_usd'] ?? 0);
    }

    public function estimateFirecrawlSearchUsd(int $resultLimit = 10): float
    {
        $perCredit = (float) config('billing.vendors.firecrawl.usd_per_credit', 0.0032);
        $creditsPer10 = (float) config('billing.vendors.firecrawl.search_credits_per_10_results', 2);
        $credits = max(1, (int) ceil($resultLimit / 10) * $creditsPer10);

        return $credits * $perCredit;
    }

    public function estimateFirecrawlScrapeUsd(): float
    {
        $perCredit = (float) config('billing.vendors.firecrawl.usd_per_credit', 0.0032);
        $credits = (int) config('billing.vendors.firecrawl.scrape_credits', 1);

        return max($perCredit, $credits * $perCredit);
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function writeEntry(
        User $user,
        string $action,
        BillingVendor $vendor,
        float $amountPence,
        ?float $cogsUsd,
        ?float $multiplier,
        array $meta,
        ?string $idempotencyKey,
        bool $requirePlatform,
        bool $requireCredits,
        ?CarbonInterface $expiresAt = null,
    ): CreditLedgerEntry {
        $amountPence = $this->roundPence($amountPence);

        if ($idempotencyKey !== null) {
            $existing = CreditLedgerEntry::query()->where('idempotency_key', $idempotencyKey)->first();

            if ($existing !== null) {
                return $existing;
            }
        }

        return DB::transaction(function () use (
            $user,
            $action,
            $vendor,
            $amountPence,
            $cogsUsd,
            $multiplier,
            $meta,
            $idempotencyKey,
            $requirePlatform,
            $requireCredits,
            $expiresAt,
        ): CreditLedgerEntry {
            $balance = CreditBalance::query()->lockForUpdate()->firstOrCreate(
                ['user_id' => $user->id],
                ['balance_pence' => 0, 'starter_allowance_exhausted' => false],
            );

            $this->syncExpiredLots($user, $balance);

            if ($requirePlatform && ! $this->hasPlatformSubscription($user)) {
                throw new PlatformSubscriptionRequiredException;
            }

            $current = $this->sumUnexpiredRemaining($user);

            if ($requireCredits && $amountPence < 0) {
                $needed = abs($amountPence);
                if ($current < $needed) {
                    throw new InsufficientCreditsException(
                        requiredPence: $needed,
                        balancePence: $current,
                        message: 'Not enough credits for this charge. Subscribe to the platform plan for monthly credit value, or top up on the Billing page.',
                    );
                }

                $this->consumeCreditLots($user, $needed);
            }

            $remainingPence = $amountPence > 0 ? $amountPence : null;

            $entry = CreditLedgerEntry::query()->create([
                'user_id' => $user->id,
                'action' => $action,
                'vendor' => $vendor,
                'cogs_usd' => $cogsUsd,
                'multiplier' => $multiplier,
                'amount_pence' => $amountPence,
                'balance_after_pence' => 0,
                'meta' => $meta === [] ? null : $meta,
                'idempotency_key' => $idempotencyKey ?? (string) Str::uuid(),
                'expires_at' => $amountPence > 0 ? $expiresAt : null,
                'remaining_pence' => $remainingPence,
            ]);

            $next = $this->sumUnexpiredRemaining($user);
            $balance->forceFill(['balance_pence' => max(0, $next)])->save();
            $entry->forceFill(['balance_after_pence' => $this->roundPence((float) $balance->balance_pence)])->save();

            if (! $this->hasPlatformSubscription($user) && $this->claimBonusRemainingPence($user) <= $this->minRunBalancePence()) {
                $balance->forceFill(['starter_allowance_exhausted' => true])->save();
            }

            return $entry->refresh();
        });
    }

    private function syncExpiredLots(User $user, ?CreditBalance $lockedBalance = null): void
    {
        $expired = CreditLedgerEntry::query()
            ->where('user_id', $user->id)
            ->where('amount_pence', '>', 0)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->where('remaining_pence', '>', 0)
            ->get();

        if ($expired->isEmpty()) {
            if ($lockedBalance !== null) {
                $lockedBalance->forceFill([
                    'balance_pence' => max(0, $this->sumUnexpiredRemaining($user)),
                ])->save();
            }

            return;
        }

        foreach ($expired as $lot) {
            $lot->forceFill(['remaining_pence' => 0])->save();
        }

        $balance = $lockedBalance ?? CreditBalance::query()->firstOrCreate(
            ['user_id' => $user->id],
            ['balance_pence' => 0, 'starter_allowance_exhausted' => false],
        );

        $balance->forceFill([
            'balance_pence' => max(0, $this->sumUnexpiredRemaining($user)),
        ])->save();
    }

    private function sumUnexpiredRemaining(User $user): float
    {
        return $this->roundPence((float) CreditLedgerEntry::query()
            ->where('user_id', $user->id)
            ->where('amount_pence', '>', 0)
            ->where(function ($query): void {
                $query->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            })
            ->sum(DB::raw('COALESCE(remaining_pence, 0)')));
    }

    private function consumeCreditLots(User $user, float $neededPence): void
    {
        $needed = $this->roundPence($neededPence);

        $lots = CreditLedgerEntry::query()
            ->where('user_id', $user->id)
            ->where('amount_pence', '>', 0)
            ->where('remaining_pence', '>', 0)
            ->where(function ($query): void {
                $query->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            })
            ->orderByRaw('CASE WHEN expires_at IS NULL THEN 1 ELSE 0 END')
            ->orderBy('expires_at')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        foreach ($lots as $lot) {
            if ($needed <= 0) {
                break;
            }

            $available = $this->roundPence((float) ($lot->remaining_pence ?? 0));
            $take = min($available, $needed);
            $lot->forceFill([
                'remaining_pence' => $this->roundPence($available - $take),
            ])->save();
            $needed = $this->roundPence($needed - $take);
        }

        if ($needed > 0.001) {
            throw new InsufficientCreditsException(
                requiredPence: $neededPence,
                balancePence: $this->sumUnexpiredRemaining($user),
                message: 'Not enough credits for this charge. Subscribe to the platform plan for monthly credit value, or top up on the Billing page.',
            );
        }
    }

    private function balanceRow(User $user): CreditBalance
    {
        return CreditBalance::query()->firstOrCreate(
            ['user_id' => $user->id],
            ['balance_pence' => 0, 'starter_allowance_exhausted' => false],
        );
    }

    private function roundPence(float $pence): float
    {
        // Hundredths of a penny = £0.0001 grain.
        return round($pence, 2);
    }
}
