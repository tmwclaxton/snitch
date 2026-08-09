<?php

namespace App\Services\Billing;

use App\Enums\BillingVendor;
use App\Exceptions\InsufficientCreditsException;
use App\Exceptions\PlatformSubscriptionRequiredException;
use App\Models\CreditBalance;
use App\Models\CreditLedgerEntry;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class UsageBillingService
{
    public function balancePence(User $user): int
    {
        return (int) ($this->balanceRow($user)->balance_pence ?? 0);
    }

    public function hasPlatformSubscription(User $user): bool
    {
        $type = (string) config('billing.subscription_type', 'default');
        $subscription = $user->subscription($type);

        return $subscription !== null && $subscription->valid();
    }

    public function assertCanRun(User $user, int $estimatedPence = 1): void
    {
        if (! $this->hasPlatformSubscription($user)) {
            throw new PlatformSubscriptionRequiredException;
        }

        $balance = $this->balancePence($user);

        if ($balance < max(1, $estimatedPence)) {
            throw new InsufficientCreditsException(
                requiredPence: max(1, $estimatedPence),
                balancePence: $balance,
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
            requirePlatform: true,
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

    public function estimatePence(string $action, BillingVendor|string $vendor, ?float $cogsUsd = null): int
    {
        $vendorEnum = $vendor instanceof BillingVendor ? $vendor : BillingVendor::from($vendor);

        return $this->pricePenceFromCogs($action, $vendorEnum, $cogsUsd);
    }

    /**
     * Daily charged usage (Apify / NanoGPT / Firecrawl) for stacked spend charts.
     *
     * @return array{
     *     days: int,
     *     from: string,
     *     to: string,
     *     points: list<array{date: string, label: string, apify: int, nanogpt: int, firecrawl: int, total: int}>
     * }
     */
    public function dailySpendSeries(User $user, int $days = 30): array
    {
        $days = max(7, min(90, $days));
        $from = now()->subDays($days - 1)->startOfDay();
        $to = now()->endOfDay();
        $vendorKeys = [
            BillingVendor::Apify->value,
            BillingVendor::NanoGpt->value,
            BillingVendor::Firecrawl->value,
        ];

        /** @var array<string, array{date: string, label: string, apify: int, nanogpt: int, firecrawl: int, total: int}> $buckets */
        $buckets = [];

        for ($offset = 0; $offset < $days; $offset++) {
            $day = $from->copy()->addDays($offset);
            $key = $day->toDateString();
            $buckets[$key] = [
                'date' => $key,
                'label' => $day->format('j M'),
                'apify' => 0,
                'nanogpt' => 0,
                'firecrawl' => 0,
                'total' => 0,
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
            $key = $entry->created_at?->toDateString();

            if ($key === null || ! isset($buckets[$key])) {
                continue;
            }

            $vendor = $entry->vendor instanceof BillingVendor
                ? $entry->vendor->value
                : (string) $entry->vendor;

            if (! array_key_exists($vendor, $buckets[$key])) {
                continue;
            }

            $pence = abs((int) $entry->amount_pence);
            $buckets[$key][$vendor] += $pence;
            $buckets[$key]['total'] += $pence;
        }

        return [
            'days' => $days,
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'points' => array_values($buckets),
        ];
    }

    /**
     * @return array{
     *     balance_pence: int,
     *     subscribed: bool,
     *     platform_fee_pence: int,
     *     period: array{from: string, to: string},
     *     vendors: array<string, array{spend_pence: int, entries: int}>,
     *     period_spend_pence: int,
     *     all_time_spend_pence: int,
     *     recent: list<array<string, mixed>>
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
        ];

        $vendors = [];
        foreach ($vendorKeys as $key) {
            $vendors[$key] = ['spend_pence' => 0, 'entries' => 0];
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
                'spend_pence' => (int) $row->spend_pence,
                'entries' => (int) $row->entries,
            ];
        }

        $periodSpend = array_sum(array_column($vendors, 'spend_pence'));

        $allTimeSpend = (int) CreditLedgerEntry::query()
            ->where('user_id', $user->id)
            ->where('amount_pence', '<', 0)
            ->whereIn('vendor', $vendorKeys)
            ->sum(DB::raw('ABS(amount_pence)'));

        $recent = CreditLedgerEntry::query()
            ->where('user_id', $user->id)
            ->whereIn('vendor', [...$vendorKeys, BillingVendor::Bonus->value, BillingVendor::Topup->value])
            ->orderByDesc('id')
            ->limit(40)
            ->get()
            ->map(fn (CreditLedgerEntry $entry): array => [
                'id' => $entry->id,
                'action' => $entry->action,
                'vendor' => $entry->vendor instanceof BillingVendor ? $entry->vendor->value : (string) $entry->vendor,
                'amount_pence' => $entry->amount_pence,
                'created_at' => $entry->created_at?->toIso8601String(),
                'meta' => $entry->meta ?? [],
            ])
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
        ];
    }

    public function pricePenceFromCogs(string $action, BillingVendor $vendor, ?float $cogsUsd): int
    {
        $floorUsd = (float) config("billing.actions.{$action}.floor_usd", config('billing.vendors.apify.floor_usd', 0.01));
        $cogs = $cogsUsd !== null && $cogsUsd > 0 ? $cogsUsd : $floorUsd;
        $gbp = $cogs * (float) config('billing.usd_to_gbp', 0.79);
        $priced = $gbp * (float) config('billing.price_multiplier', 1.4);

        return max(1, (int) ceil($priced * 100));
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
        int $amountPence,
        ?float $cogsUsd,
        ?float $multiplier,
        array $meta,
        ?string $idempotencyKey,
        bool $requirePlatform,
        bool $requireCredits,
    ): CreditLedgerEntry {
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

            $next = (int) $balance->balance_pence + $amountPence;

            if ($requireCredits && $amountPence < 0 && $next < 0) {
                throw new InsufficientCreditsException(
                    requiredPence: abs($amountPence),
                    balancePence: (int) $balance->balance_pence,
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
                'balance_after_pence' => (int) $balance->balance_pence,
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
}
