<?php

namespace App\Services\Admin;

use App\Enums\TrackedAccountKind;
use App\Models\CreditLedgerEntry;
use App\Models\ReferralCode;
use App\Models\ReferralVisit;
use App\Models\User;
use App\Services\Billing\LedgerChargePresenter;
use App\Services\Billing\UsageBillingService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AdminReferralService
{
    /** @var list<string> */
    private const PAYMENT_ACTIONS = ['subscription_bonus', 'credits.topup'];

    public function __construct(
        private UsageBillingService $billing,
        private LedgerChargePresenter $presenter,
    ) {}

    /**
     * @return array{codes: list<array<string, mixed>>}
     */
    public function index(): array
    {
        $codes = ReferralCode::query()
            ->orderByDesc('created_at')
            ->get();

        $codeIds = $codes->pluck('id')->all();

        if ($codeIds === []) {
            return ['codes' => []];
        }

        $signups = User::query()
            ->whereIn('referral_code_id', $codeIds)
            ->selectRaw('referral_code_id, COUNT(*) as aggregate')
            ->groupBy('referral_code_id')
            ->pluck('aggregate', 'referral_code_id');

        $converted = User::query()
            ->whereIn('referral_code_id', $codeIds)
            ->whereHas('subscriptions', function (Builder $query): void {
                $query->whereIn('stripe_status', ['active', 'trialing']);
            })
            ->selectRaw('referral_code_id, COUNT(*) as aggregate')
            ->groupBy('referral_code_id')
            ->pluck('aggregate', 'referral_code_id');

        $usageSpend = $this->aggregateLedgerByReferral($codeIds, spend: true);
        $payments = $this->aggregateLedgerByReferral($codeIds, spend: false, payments: true);
        $clicks = ReferralVisit::query()
            ->whereIn('referral_code_id', $codeIds)
            ->selectRaw('referral_code_id, COUNT(*) as aggregate')
            ->groupBy('referral_code_id')
            ->pluck('aggregate', 'referral_code_id');

        return [
            'codes' => $codes->map(fn (ReferralCode $code): array => [
                'id' => $code->id,
                'code' => $code->code,
                'name' => $code->name,
                'notes' => $code->notes,
                'is_active' => $code->is_active,
                'public_url' => $code->publicUrl(),
                'signups' => (int) ($signups[$code->id] ?? 0),
                'converted' => (int) ($converted[$code->id] ?? 0),
                'clicks' => (int) ($clicks[$code->id] ?? 0),
                'lifetime_usage_pence' => $this->roundPence((float) ($usageSpend[$code->id] ?? 0)),
                'lifetime_payments_pence' => $this->roundPence((float) ($payments[$code->id] ?? 0)),
                'created_at' => $code->created_at?->toIso8601String(),
            ])->values()->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function show(
        ReferralCode $referral,
        string $grain = 'day',
        ?int $periods = null,
        ?string $search = null,
        ?string $sort = null,
        ?string $direction = null,
        int $page = 1,
        ?int $expandedUserId = null,
    ): array {
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

        $referredUserIds = User::query()
            ->where('referral_code_id', $referral->id)
            ->pluck('id')
            ->all();

        $kpis = $this->detailKpis($referral, $referredUserIds);

        return [
            'referral' => [
                'id' => $referral->id,
                'code' => $referral->code,
                'name' => $referral->name,
                'notes' => $referral->notes,
                'is_active' => $referral->is_active,
                'public_url' => $referral->publicUrl(),
                'created_at' => $referral->created_at?->toIso8601String(),
            ],
            'grain' => $grain,
            'period_count' => $periodCount,
            'days' => (int) $from->diffInDays($to) + 1,
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'kpis' => $kpis,
            'signupsSeries' => $this->signupsSeries($referral->id, $grain, $from, $to, $periodCount),
            'usageSeries' => $this->usageSpendSeries($referredUserIds, $grain, $from, $to, $periodCount),
            'paymentsSeries' => $this->paymentsSeries($referredUserIds, $grain, $from, $to, $periodCount),
            'clicksVsSignupsSeries' => $this->clicksVsSignupsSeries($referral->id, $grain, $from, $to, $periodCount),
            'users' => $this->paginatedReferredUsers(
                $referral,
                $search,
                $sort,
                $direction,
                $page,
                $expandedUserId,
            ),
            'filters' => [
                'search' => $search ?? '',
                'sort' => $this->normalizeSort($sort),
                'direction' => $this->normalizeDirection($direction),
                'expanded_user_id' => $expandedUserId,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, ?User $creator = null): ReferralCode
    {
        $code = Str::lower(trim((string) $data['code']));

        return ReferralCode::query()->create([
            'code' => $code,
            'name' => trim((string) $data['name']),
            'notes' => filled($data['notes'] ?? null) ? trim((string) $data['notes']) : null,
            'is_active' => (bool) ($data['is_active'] ?? true),
            'created_by' => $creator?->id,
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(ReferralCode $referral, array $data): ReferralCode
    {
        $referral->forceFill([
            'name' => trim((string) $data['name']),
            'notes' => filled($data['notes'] ?? null) ? trim((string) $data['notes']) : null,
            'is_active' => (bool) ($data['is_active'] ?? $referral->is_active),
        ])->save();

        return $referral->fresh() ?? $referral;
    }

    /**
     * @param  list<int>  $referredUserIds
     * @return array{
     *     clicks: int,
     *     signups: int,
     *     subscribed: int,
     *     lifetime_usage_pence: float,
     *     lifetime_payments_pence: float
     * }
     */
    private function detailKpis(ReferralCode $referral, array $referredUserIds): array
    {
        $clicks = ReferralVisit::query()
            ->where('referral_code_id', $referral->id)
            ->count();

        $signups = count($referredUserIds);

        $subscribed = $signups === 0
            ? 0
            : User::query()
                ->whereIn('id', $referredUserIds)
                ->whereHas('subscriptions', function (Builder $query): void {
                    $query->whereIn('stripe_status', ['active', 'trialing']);
                })
                ->count();

        $usage = $signups === 0
            ? 0.0
            : (float) CreditLedgerEntry::query()
                ->whereIn('user_id', $referredUserIds)
                ->where('amount_pence', '<', 0)
                ->whereIn('vendor', UsageBillingService::spendVendorKeys())
                ->sum(DB::raw('ABS(amount_pence)'));

        $payments = $signups === 0
            ? 0.0
            : (float) CreditLedgerEntry::query()
                ->whereIn('user_id', $referredUserIds)
                ->where('amount_pence', '>', 0)
                ->whereIn('action', self::PAYMENT_ACTIONS)
                ->sum('amount_pence');

        return [
            'clicks' => $clicks,
            'signups' => $signups,
            'subscribed' => $subscribed,
            'lifetime_usage_pence' => $this->roundPence($usage),
            'lifetime_payments_pence' => $this->roundPence($payments),
        ];
    }

    /**
     * @return list<array{week_start: string, label: string, count: int}>
     */
    private function signupsSeries(int $referralCodeId, string $grain, \DateTimeInterface $from, \DateTimeInterface $to, int $periodCount): array
    {
        $buckets = $this->emptyCountBuckets($grain, $from, $periodCount);

        $rows = User::query()
            ->where('referral_code_id', $referralCodeId)
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

        return array_values(array_map(fn (array $bucket): array => [
            'week_start' => $bucket['date'],
            'label' => $bucket['label'],
            'count' => $bucket['count'],
        ], $buckets));
    }

    /**
     * @param  list<int>  $userIds
     * @return list<array{week_start: string, label: string, count: int, pence: float}>
     */
    private function usageSpendSeries(array $userIds, string $grain, \DateTimeInterface $from, \DateTimeInterface $to, int $periodCount): array
    {
        $buckets = $this->emptyPenceBuckets($grain, $from, $periodCount);

        if ($userIds === []) {
            return array_values(array_map(fn (array $bucket): array => [
                'week_start' => $bucket['date'],
                'label' => $bucket['label'],
                'count' => 0,
                'pence' => 0.0,
            ], $buckets));
        }

        $entries = CreditLedgerEntry::query()
            ->whereIn('user_id', $userIds)
            ->where('amount_pence', '<', 0)
            ->whereIn('vendor', UsageBillingService::spendVendorKeys())
            ->where('created_at', '>=', $from)
            ->where('created_at', '<=', $to)
            ->get(['amount_pence', 'created_at']);

        foreach ($entries as $entry) {
            if ($entry->created_at === null) {
                continue;
            }

            $key = $this->bucketKey($grain, $entry->created_at);
            if (! isset($buckets[$key])) {
                continue;
            }

            $buckets[$key]['pence'] = $this->roundPence(
                $buckets[$key]['pence'] + abs((float) $entry->amount_pence),
            );
        }

        return array_values(array_map(fn (array $bucket): array => [
            'week_start' => $bucket['date'],
            'label' => $bucket['label'],
            'count' => 0,
            'pence' => $bucket['pence'],
        ], $buckets));
    }

    /**
     * @param  list<int>  $userIds
     * @return list<array{week_start: string, label: string, count: int, pence: float}>
     */
    private function paymentsSeries(array $userIds, string $grain, \DateTimeInterface $from, \DateTimeInterface $to, int $periodCount): array
    {
        $buckets = $this->emptyPenceBuckets($grain, $from, $periodCount);

        if ($userIds === []) {
            return array_values(array_map(fn (array $bucket): array => [
                'week_start' => $bucket['date'],
                'label' => $bucket['label'],
                'count' => 0,
                'pence' => 0.0,
            ], $buckets));
        }

        $entries = CreditLedgerEntry::query()
            ->whereIn('user_id', $userIds)
            ->where('amount_pence', '>', 0)
            ->whereIn('action', self::PAYMENT_ACTIONS)
            ->where('created_at', '>=', $from)
            ->where('created_at', '<=', $to)
            ->get(['amount_pence', 'created_at']);

        foreach ($entries as $entry) {
            if ($entry->created_at === null) {
                continue;
            }

            $key = $this->bucketKey($grain, $entry->created_at);
            if (! isset($buckets[$key])) {
                continue;
            }

            $buckets[$key]['pence'] = $this->roundPence(
                $buckets[$key]['pence'] + (float) $entry->amount_pence,
            );
        }

        return array_values(array_map(fn (array $bucket): array => [
            'week_start' => $bucket['date'],
            'label' => $bucket['label'],
            'count' => 0,
            'pence' => $bucket['pence'],
        ], $buckets));
    }

    /**
     * @return list<array{week_start: string, label: string, clicks: int, signups: int}>
     */
    private function clicksVsSignupsSeries(int $referralCodeId, string $grain, \DateTimeInterface $from, \DateTimeInterface $to, int $periodCount): array
    {
        $buckets = $this->emptyDualBuckets($grain, $from, $periodCount);

        $visits = ReferralVisit::query()
            ->where('referral_code_id', $referralCodeId)
            ->where('created_at', '>=', $from)
            ->where('created_at', '<=', $to)
            ->get(['created_at']);

        foreach ($visits as $visit) {
            if ($visit->created_at === null) {
                continue;
            }

            $key = $this->bucketKey($grain, $visit->created_at);
            if (isset($buckets[$key])) {
                $buckets[$key]['clicks']++;
            }
        }

        $signups = User::query()
            ->where('referral_code_id', $referralCodeId)
            ->where('created_at', '>=', $from)
            ->where('created_at', '<=', $to)
            ->get(['created_at']);

        foreach ($signups as $signup) {
            if ($signup->created_at === null) {
                continue;
            }

            $key = $this->bucketKey($grain, $signup->created_at);
            if (isset($buckets[$key])) {
                $buckets[$key]['signups']++;
            }
        }

        return array_values(array_map(fn (array $bucket): array => [
            'week_start' => $bucket['date'],
            'label' => $bucket['label'],
            'clicks' => $bucket['clicks'],
            'signups' => $bucket['signups'],
        ], $buckets));
    }

    /**
     * @return array{
     *     data: list<array<string, mixed>>,
     *     current_page: int,
     *     last_page: int,
     *     per_page: int,
     *     total: int,
     *     links: list<array<string, mixed>>
     * }
     */
    private function paginatedReferredUsers(
        ReferralCode $referral,
        ?string $search,
        ?string $sort,
        ?string $direction,
        int $page,
        ?int $expandedUserId,
    ): array {
        $sort = $this->normalizeSort($sort);
        $direction = $this->normalizeDirection($direction);

        $query = User::query()
            ->where('referral_code_id', $referral->id)
            ->withCount([
                'trackedAccounts as snitch_count' => fn (Builder $builder) => $builder
                    ->where('kind', TrackedAccountKind::Competitor),
            ])
            ->with(['subscriptions' => fn ($builder) => $builder->latest()]);

        if (filled($search)) {
            $needle = '%'.Str::lower(trim($search)).'%';
            $query->where(function (Builder $builder) use ($needle): void {
                $builder
                    ->whereRaw('LOWER(email) LIKE ?', [$needle])
                    ->orWhereRaw('LOWER(name) LIKE ?', [$needle]);
            });
        }

        if (in_array($sort, ['usage_spend', 'payments'], true)) {
            $vendorKeys = UsageBillingService::spendVendorKeys();
            $paymentActions = implode("','", self::PAYMENT_ACTIONS);

            if ($sort === 'usage_spend') {
                $query->select('users.*')
                    ->selectSub(
                        CreditLedgerEntry::query()
                            ->selectRaw('COALESCE(SUM(ABS(amount_pence)), 0)')
                            ->whereColumn('credit_ledger_entries.user_id', 'users.id')
                            ->where('amount_pence', '<', 0)
                            ->whereIn('vendor', $vendorKeys),
                        'sort_usage_pence',
                    )
                    ->orderBy('sort_usage_pence', $direction);
            } else {
                $query->select('users.*')
                    ->selectSub(
                        CreditLedgerEntry::query()
                            ->selectRaw('COALESCE(SUM(amount_pence), 0)')
                            ->whereColumn('credit_ledger_entries.user_id', 'users.id')
                            ->where('amount_pence', '>', 0)
                            ->whereIn('action', self::PAYMENT_ACTIONS),
                        'sort_payments_pence',
                    )
                    ->orderBy('sort_payments_pence', $direction);
            }
        } else {
            $query->orderBy('created_at', $direction);
        }

        /** @var LengthAwarePaginator<int, User> $paginator */
        $paginator = $query->paginate(25, ['*'], 'page', $page);

        $userIds = collect($paginator->items())->pluck('id')->all();
        $usageTotals = $this->userLedgerTotals($userIds, spend: true);
        $paymentTotals = $this->userLedgerTotals($userIds, spend: false, payments: true);
        $lastActivity = $this->userLastActivity($userIds);

        $data = collect($paginator->items())->map(function (User $user) use (
            $usageTotals,
            $paymentTotals,
            $lastActivity,
            $expandedUserId,
        ): array {
            $row = [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'signed_up_at' => $user->created_at?->toIso8601String(),
                'created_via' => $user->created_via,
                'plan_status' => $this->planStatus($user),
                'balance_pence' => $this->roundPence($this->billing->balancePence($user)),
                'lifetime_usage_pence' => $this->roundPence((float) ($usageTotals[$user->id] ?? 0)),
                'lifetime_payments_pence' => $this->roundPence((float) ($paymentTotals[$user->id] ?? 0)),
                'last_activity_at' => $lastActivity[$user->id] ?? null,
                'snitch_count' => (int) ($user->snitch_count ?? 0),
                'subscription_summary' => $this->subscriptionSummary($user),
                'recent_ledger' => [],
            ];

            if ($expandedUserId !== null && $expandedUserId === $user->id) {
                $row['recent_ledger'] = $this->recentLedger($user);
            }

            return $row;
        })->values()->all();

        return [
            'data' => $data,
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
            'links' => collect($paginator->linkCollection())->values()->all(),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function recentLedger(User $user): array
    {
        return CreditLedgerEntry::query()
            ->where('user_id', $user->id)
            ->orderByDesc('id')
            ->limit(8)
            ->get()
            ->map(function (CreditLedgerEntry $entry): array {
                $presented = $this->presenter->present(
                    $entry->action,
                    is_array($entry->meta) ? $entry->meta : null,
                );

                return [
                    'id' => $entry->id,
                    'action' => $entry->action,
                    'description' => $presented['description'],
                    'amount_pence' => $this->roundPence((float) $entry->amount_pence),
                    'created_at' => $entry->created_at?->toIso8601String(),
                ];
            })
            ->all();
    }

    /**
     * @return array{status: string, plan_name: string|null, started_at: string|null, ends_at: string|null}
     */
    private function subscriptionSummary(User $user): array
    {
        $type = (string) config('billing.subscription_type', 'default');
        $subscription = $user->subscription($type);

        if ($subscription === null) {
            return [
                'status' => 'none',
                'plan_name' => null,
                'started_at' => null,
                'ends_at' => null,
            ];
        }

        return [
            'status' => $this->planStatus($user),
            'plan_name' => 'Platform',
            'started_at' => $subscription->created_at?->toIso8601String(),
            'ends_at' => $subscription->ends_at?->toIso8601String(),
        ];
    }

    private function planStatus(User $user): string
    {
        $type = (string) config('billing.subscription_type', 'default');
        $subscription = $user->subscription($type);

        if ($subscription === null) {
            return 'none';
        }

        if ($subscription->valid()) {
            return $subscription->stripe_status === 'trialing' ? 'trialing' : 'active';
        }

        return match ($subscription->stripe_status) {
            'past_due' => 'past_due',
            'canceled', 'cancelled' => 'cancelled',
            default => (string) $subscription->stripe_status,
        };
    }

    /**
     * @param  list<int>  $codeIds
     * @return array<int, float>
     */
    private function aggregateLedgerByReferral(array $codeIds, bool $spend, bool $payments = false): array
    {
        $query = CreditLedgerEntry::query()
            ->join('users', 'users.id', '=', 'credit_ledger_entries.user_id')
            ->whereIn('users.referral_code_id', $codeIds)
            ->selectRaw('users.referral_code_id, SUM('.($spend ? 'ABS(credit_ledger_entries.amount_pence)' : 'credit_ledger_entries.amount_pence').') as aggregate')
            ->groupBy('users.referral_code_id');

        if ($spend) {
            $query
                ->where('credit_ledger_entries.amount_pence', '<', 0)
                ->whereIn('credit_ledger_entries.vendor', UsageBillingService::spendVendorKeys());
        } elseif ($payments) {
            $query
                ->where('credit_ledger_entries.amount_pence', '>', 0)
                ->whereIn('credit_ledger_entries.action', self::PAYMENT_ACTIONS);
        }

        return $query
            ->pluck('aggregate', 'referral_code_id')
            ->map(fn ($value): float => $this->roundPence((float) $value))
            ->all();
    }

    /**
     * @param  list<int>  $userIds
     * @return array<int, float>
     */
    private function userLedgerTotals(array $userIds, bool $spend, bool $payments = false): array
    {
        if ($userIds === []) {
            return [];
        }

        $query = CreditLedgerEntry::query()
            ->whereIn('user_id', $userIds)
            ->selectRaw('user_id, SUM('.($spend ? 'ABS(amount_pence)' : 'amount_pence').') as aggregate')
            ->groupBy('user_id');

        if ($spend) {
            $query
                ->where('amount_pence', '<', 0)
                ->whereIn('vendor', UsageBillingService::spendVendorKeys());
        } elseif ($payments) {
            $query
                ->where('amount_pence', '>', 0)
                ->whereIn('action', self::PAYMENT_ACTIONS);
        }

        return $query
            ->pluck('aggregate', 'user_id')
            ->map(fn ($value): float => $this->roundPence((float) $value))
            ->all();
    }

    /**
     * @param  list<int>  $userIds
     * @return array<int, string>
     */
    private function userLastActivity(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        return CreditLedgerEntry::query()
            ->whereIn('user_id', $userIds)
            ->selectRaw('user_id, MAX(created_at) as last_activity_at')
            ->groupBy('user_id')
            ->get()
            ->mapWithKeys(fn ($row): array => [
                (int) $row->user_id => Carbon::parse((string) $row->last_activity_at)->toIso8601String(),
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

    /**
     * @return array<string, array{date: string, label: string, pence: float}>
     */
    private function emptyPenceBuckets(string $grain, \DateTimeInterface $from, int $periodCount): array
    {
        $buckets = $this->emptyCountBuckets($grain, $from, $periodCount);

        foreach ($buckets as $key => $bucket) {
            $buckets[$key]['pence'] = 0.0;
            unset($buckets[$key]['count']);
        }

        return $buckets;
    }

    /**
     * @return array<string, array{date: string, label: string, clicks: int, signups: int}>
     */
    private function emptyDualBuckets(string $grain, \DateTimeInterface $from, int $periodCount): array
    {
        $buckets = $this->emptyCountBuckets($grain, $from, $periodCount);

        foreach ($buckets as $key => $bucket) {
            $buckets[$key]['clicks'] = 0;
            $buckets[$key]['signups'] = 0;
            unset($buckets[$key]['count']);
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

    private function normalizeSort(?string $sort): string
    {
        return in_array($sort, ['created_at', 'usage_spend', 'payments'], true)
            ? $sort
            : 'created_at';
    }

    private function normalizeDirection(?string $direction): string
    {
        return $direction === 'asc' ? 'asc' : 'desc';
    }

    private function roundPence(float $pence): float
    {
        return round($pence, 2, PHP_ROUND_HALF_UP);
    }
}
