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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class UsageBillingService
{
    public const RECENT_PREVIEW_LIMIT = 8;

    public const CHARGES_PER_PAGE = 25;

    public function balancePence(User $user): float
    {
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

    public function canRun(User $user, float $estimatedPence = 1): bool
    {
        try {
            $this->assertCanRun($user, $estimatedPence);

            return true;
        } catch (InsufficientCreditsException) {
            return false;
        }
    }

    public function assertCanRun(User $user, float $estimatedPence = 1): void
    {
        $balance = $this->balancePence($user);
        $minExclusive = $this->minRunBalancePence();
        $estimate = max(0.1, $estimatedPence);
        $required = max($minExclusive + 1, $estimate);

        if ($balance <= $minExclusive || $balance < $estimate) {
            throw new InsufficientCreditsException(
                requiredPence: $required,
                balancePence: $balance,
                message: sprintf(
                    'Your balance must be more than %dp to run this. Subscribe to the platform plan for monthly credit value, or top up on the Billing page.',
                    $minExclusive,
                ),
            );
        }
    }

    /**
     * Charge the user for vendor usage. amount_pence is negative on the ledger.
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
    ): CreditLedgerEntry {
        $vendorEnum = $vendor instanceof BillingVendor ? $vendor : BillingVendor::from($vendor);

        if (in_array($vendorEnum, [BillingVendor::Bonus, BillingVendor::Topup], true)) {
            throw new \InvalidArgumentException('Use credit helpers for bonus/top-up entries.');
        }

        $this->assertCanRun($user, 1);

        $amountPence = $this->pricePenceFromCogs($action, $vendorEnum, $cogsUsd);
        $multiplier = (float) config('billing.price_multiplier', 1.4);

        return $this->writeEntry(
            user: $user,
            action: $action,
            vendor: $vendorEnum,
            amountPence: -abs($amountPence),
            cogsUsd: $cogsUsd,
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
        );
    }

    public function estimatePence(string $action, BillingVendor|string $vendor, ?float $cogsUsd = null): float
    {
        $vendorEnum = $vendor instanceof BillingVendor ? $vendor : BillingVendor::from($vendor);

        return $this->pricePenceFromCogs($action, $vendorEnum, $cogsUsd);
    }

    /**
     * Daily charged usage (Apify / NanoGPT / Firecrawl / TikHub) for stacked spend charts.
     *
     * @return array{
     *     grain: string,
     *     period_count: int,
     *     days: int,
     *     from: string,
     *     to: string,
     *     points: list<array{date: string, label: string, apify: float, nanogpt: float, firecrawl: float, tikhub: float, total: float}>
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
     *     points: list<array{date: string, label: string, apify: float, nanogpt: float, firecrawl: float, tikhub: float, total: float}>
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

        $vendorKeys = [
            BillingVendor::Apify->value,
            BillingVendor::NanoGpt->value,
            BillingVendor::Firecrawl->value,
            BillingVendor::TikHub->value,
        ];

        /** @var array<string, array{date: string, label: string, apify: float, nanogpt: float, firecrawl: float, tikhub: float, total: float}> $buckets */
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

        $vendorKeys = [
            BillingVendor::Apify->value,
            BillingVendor::NanoGpt->value,
            BillingVendor::Firecrawl->value,
            BillingVendor::TikHub->value,
        ];

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

        $recentVendors = [...$vendorKeys, BillingVendor::Bonus->value, BillingVendor::Topup->value];

        $recentQuery = CreditLedgerEntry::query()
            ->where('user_id', $user->id)
            ->whereIn('vendor', $recentVendors);

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
        ];
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
     *     vendor: string,
     *     amount_pence: float,
     *     balance_after_pence?: float,
     *     created_at: string|null
     * }
     */
    private function mapLedgerEntry(CreditLedgerEntry $entry, bool $includeBalance = false): array
    {
        $mapped = [
            'id' => $entry->id,
            'action' => $entry->action,
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
        $floorUsd = (float) config("billing.actions.{$action}.floor_usd", config('billing.vendors.apify.floor_usd', 0.01));
        $cogs = $cogsUsd !== null && $cogsUsd > 0 ? $cogsUsd : $floorUsd;
        $gbp = $cogs * (float) config('billing.usd_to_gbp', 0.79);
        $priced = $gbp * (float) config('billing.price_multiplier', 1.4);
        $pence = $priced * 100;

        if ($vendor === BillingVendor::NanoGpt) {
            $min = (float) config('billing.vendors.nanogpt.min_charge_pence', 0.2);

            // Ceil to tenths of a penny so NanoGPT can bill 0.2p accurately.
            return max($min, $this->roundPence(ceil(($pence * 10) - 1e-9) / 10));
        }

        return (float) max(1, (int) ceil($pence - 1e-9));
    }

    public function estimateNanoGptChatUsd(?int $inputTokens, ?int $outputTokens, string $model, string $floorKey = 'chat'): float
    {
        $floor = (float) config("billing.vendors.nanogpt.floors_usd.{$floorKey}", 0.002);
        $rates = config("billing.vendors.nanogpt.models.{$model}");

        if (! is_array($rates) || ($inputTokens === null && $outputTokens === null)) {
            return $floor;
        }

        $in = max(0, (int) ($inputTokens ?? 0));
        $out = max(0, (int) ($outputTokens ?? 0));
        $usd = ($in / 1_000_000) * (float) ($rates['input_per_m_usd'] ?? 0)
            + ($out / 1_000_000) * (float) ($rates['output_per_m_usd'] ?? 0);

        return max($floor, $usd);
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
        ): CreditLedgerEntry {
            $balance = CreditBalance::query()->lockForUpdate()->firstOrCreate(
                ['user_id' => $user->id],
                ['balance_pence' => 0],
            );

            if ($requirePlatform && ! $this->hasPlatformSubscription($user)) {
                throw new PlatformSubscriptionRequiredException;
            }

            $current = $this->roundPence((float) $balance->balance_pence);
            $next = $this->roundPence($current + $amountPence);

            if ($requireCredits && $amountPence < 0 && $next < 0) {
                throw new InsufficientCreditsException(
                    requiredPence: abs($amountPence),
                    balancePence: $current,
                    message: 'Not enough credits for this charge. Subscribe to the platform plan for monthly credit value, or top up on the Billing page.',
                );
            }

            $balance->forceFill(['balance_pence' => max(0, $next)])->save();

            return CreditLedgerEntry::query()->create([
                'user_id' => $user->id,
                'action' => $action,
                'vendor' => $vendor,
                'cogs_usd' => $cogsUsd,
                'multiplier' => $multiplier,
                'amount_pence' => $amountPence,
                'balance_after_pence' => $this->roundPence((float) $balance->balance_pence),
                'meta' => $meta === [] ? null : $meta,
                'idempotency_key' => $idempotencyKey ?? (string) Str::uuid(),
            ]);
        });
    }

    private function balanceRow(User $user): CreditBalance
    {
        return CreditBalance::query()->firstOrCreate(
            ['user_id' => $user->id],
            ['balance_pence' => 0],
        );
    }

    private function roundPence(float $pence): float
    {
        return round($pence, 1);
    }
}
