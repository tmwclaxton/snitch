<?php

namespace App\Services\Admin;

use App\Enums\BillingVendor;
use App\Models\CreditLedgerEntry;
use App\Models\McpToolInvocation;
use App\Models\Post;
use App\Models\PostAnalysis;
use App\Models\TrackedAccount;
use App\Models\User;
use App\Services\Billing\LedgerChargePresenter;
use App\Services\Billing\UsageBillingService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

class AdminUserService
{
    public function __construct(
        private UsageBillingService $billing,
        private LedgerChargePresenter $presenter,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function index(
        ?string $search = null,
        ?string $sort = null,
        ?string $direction = null,
        ?string $plan = null,
        int $page = 1,
    ): array {
        $sort = $this->normalizeSort($sort);
        $direction = $this->normalizeDirection($direction);
        $plan = $this->normalizePlanFilter($plan);

        $query = User::query()
            ->with(['referralCode:id,code,name'])
            ->withCount('trackedAccounts as snitch_count');

        if ($search !== null && $search !== '') {
            $needle = '%'.addcslashes($search, '%_\\').'%';
            $query->where(function (Builder $builder) use ($needle): void {
                $builder
                    ->where('email', 'like', $needle)
                    ->orWhere('name', 'like', $needle);
            });
        }

        if ($plan === 'subscribed') {
            $query->whereHas('subscriptions', function (Builder $builder): void {
                $builder->whereIn('stripe_status', ['active', 'trialing']);
            });
        } elseif ($plan === 'none') {
            $query->whereDoesntHave('subscriptions', function (Builder $builder): void {
                $builder->whereIn('stripe_status', ['active', 'trialing']);
            });
        }

        if ($sort === 'email') {
            $query->orderBy('email', $direction);
        } elseif ($sort === 'name') {
            $query->orderBy('name', $direction);
        } elseif ($sort === 'balance') {
            $query->select('users.*')
                ->selectSub(
                    CreditLedgerEntry::query()
                        ->selectRaw('COALESCE(SUM(CASE WHEN remaining_pence > 0 AND (expires_at IS NULL OR expires_at > ?) THEN remaining_pence ELSE 0 END), 0)', [now()])
                        ->whereColumn('credit_ledger_entries.user_id', 'users.id'),
                    'sort_balance_pence',
                )
                ->orderBy('sort_balance_pence', $direction);
        } else {
            $query->orderBy('created_at', $direction);
        }

        /** @var LengthAwarePaginator<int, User> $paginator */
        $paginator = $query->paginate(25, ['*'], 'page', $page);

        $userIds = collect($paginator->items())->pluck('id')->all();
        $lastActivity = $this->userLastActivity($userIds);

        $data = collect($paginator->items())->map(function (User $user) use ($lastActivity): array {
            return [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'created_at' => $user->created_at?->toIso8601String(),
                'plan_status' => $this->planStatus($user),
                'balance_pence' => $this->roundPence($this->billing->balancePence($user)),
                'referral_code' => $user->referralCode?->code,
                'referral_name' => $user->referralCode?->name,
                'last_activity_at' => $lastActivity[$user->id] ?? null,
                'snitch_count' => (int) ($user->snitch_count ?? 0),
            ];
        })->values()->all();

        return [
            'users' => [
                'data' => $data,
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'links' => $paginator->linkCollection()->toArray(),
            ],
            'filters' => [
                'search' => $search ?? '',
                'sort' => $sort,
                'direction' => $direction,
                'plan' => $plan ?? '',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function show(User $user, string $grain = 'day', ?int $periods = null): array
    {
        $range = AdminPeriodRange::resolve($grain, $periods);
        $user->loadMissing(['referralCode:id,code,name']);

        $socialAccountIds = TrackedAccount::query()
            ->where('user_id', $user->id)
            ->pluck('social_account_id')
            ->all();

        $postCount = $socialAccountIds === []
            ? 0
            : Post::query()->whereIn('social_account_id', $socialAccountIds)->count();

        $analysisCount = $socialAccountIds === []
            ? 0
            : PostAnalysis::query()
                ->whereHas('post', fn (Builder $query) => $query->whereIn('social_account_id', $socialAccountIds))
                ->count();

        return [
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'created_at' => $user->created_at?->toIso8601String(),
                'created_via' => $user->created_via,
                'claimed_at' => $user->claimed_at?->toIso8601String(),
                'plan_status' => $this->planStatus($user),
                'subscription' => $this->subscriptionSummary($user),
                'balance_pence' => $this->roundPence($this->billing->balancePence($user)),
                'referral_code' => $user->referralCode?->code,
                'referral_name' => $user->referralCode?->name,
                'counts' => [
                    'snitches' => TrackedAccount::query()->where('user_id', $user->id)->count(),
                    'posts' => $postCount,
                    'analyses' => $analysisCount,
                    'mcp_calls' => McpToolInvocation::query()->where('user_id', $user->id)->count(),
                    'ledger_entries' => CreditLedgerEntry::query()->where('user_id', $user->id)->count(),
                ],
            ],
            ...$range->meta(),
            'ledgerSeries' => $this->userLedgerSeries($user, $range),
            'mcpSeries' => $this->userMcpSeries($user, $range),
            'spendSeries' => $this->userSpendSeries($user, $range),
            'activity' => $this->userActivity($user),
        ];
    }

    /**
     * @return list<array{week_start: string, label: string, count: int}>
     */
    private function userLedgerSeries(User $user, AdminPeriodRange $range): array
    {
        $buckets = $range->emptyCountBuckets();

        CreditLedgerEntry::query()
            ->where('user_id', $user->id)
            ->where('created_at', '>=', $range->from)
            ->where('created_at', '<=', $range->to)
            ->get(['created_at'])
            ->each(function (CreditLedgerEntry $entry) use (&$buckets, $range): void {
                if ($entry->created_at === null) {
                    return;
                }

                $key = $range->bucketKey($entry->created_at);
                if (isset($buckets[$key])) {
                    $buckets[$key]['count']++;
                }
            });

        return $range->countSeries($buckets);
    }

    /**
     * @return list<array{week_start: string, label: string, count: int}>
     */
    private function userMcpSeries(User $user, AdminPeriodRange $range): array
    {
        $buckets = $range->emptyCountBuckets();

        McpToolInvocation::query()
            ->where('user_id', $user->id)
            ->where('created_at', '>=', $range->from)
            ->where('created_at', '<=', $range->to)
            ->get(['created_at'])
            ->each(function (McpToolInvocation $invocation) use (&$buckets, $range): void {
                if ($invocation->created_at === null) {
                    return;
                }

                $key = $range->bucketKey($invocation->created_at);
                if (isset($buckets[$key])) {
                    $buckets[$key]['count']++;
                }
            });

        return $range->countSeries($buckets);
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
    private function userSpendSeries(User $user, AdminPeriodRange $range): array
    {
        $vendorKeys = UsageBillingService::spendVendorKeys();
        $buckets = [];

        for ($offset = 0; $offset < $range->periodCount; $offset++) {
            $cursor = match ($range->grain) {
                'week' => $range->from->copy()->addWeeks($offset)->startOfWeek(),
                'month' => $range->from->copy()->addMonthsNoOverflow($offset)->startOfMonth(),
                default => $range->from->copy()->addDays($offset)->startOfDay(),
            };
            $key = $cursor->toDateString();
            $buckets[$key] = [
                'date' => $key,
                'label' => match ($range->grain) {
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

        CreditLedgerEntry::query()
            ->where('user_id', $user->id)
            ->where('amount_pence', '<', 0)
            ->whereIn('vendor', $vendorKeys)
            ->where('created_at', '>=', $range->from)
            ->where('created_at', '<=', $range->to)
            ->get(['vendor', 'amount_pence', 'created_at'])
            ->each(function (CreditLedgerEntry $entry) use (&$buckets, $range): void {
                if ($entry->created_at === null) {
                    return;
                }

                $key = $range->bucketKey($entry->created_at);
                if (! isset($buckets[$key])) {
                    return;
                }

                $vendor = $entry->vendor instanceof BillingVendor
                    ? $entry->vendor->value
                    : (string) $entry->vendor;

                if (! array_key_exists($vendor, $buckets[$key])) {
                    return;
                }

                $pence = abs($this->roundPence((float) $entry->amount_pence));
                $buckets[$key][$vendor] = $this->roundPence($buckets[$key][$vendor] + $pence);
                $buckets[$key]['total'] = $this->roundPence($buckets[$key]['total'] + $pence);
            });

        return [
            ...$range->meta(),
            'points' => array_values($buckets),
        ];
    }

    /**
     * @return list<array{
     *     id: string,
     *     type: string,
     *     summary: string,
     *     occurred_at: string,
     *     amount_pence: float|null,
     *     ok: bool|null
     * }>
     */
    private function userActivity(User $user, int $limit = 40): array
    {
        $events = [];

        CreditLedgerEntry::query()
            ->where('user_id', $user->id)
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
                    'amount_pence' => $this->roundPence((float) $entry->amount_pence),
                    'ok' => (float) $entry->amount_pence >= 0 ? true : null,
                ];
            });

        McpToolInvocation::query()
            ->where('user_id', $user->id)
            ->orderByDesc('id')
            ->limit(25)
            ->get()
            ->each(function (McpToolInvocation $invocation) use (&$events): void {
                $events[] = [
                    'id' => 'mcp-'.$invocation->id,
                    'type' => 'mcp',
                    'summary' => $invocation->tool,
                    'occurred_at' => $invocation->created_at?->toIso8601String() ?? now()->toIso8601String(),
                    'amount_pence' => null,
                    'ok' => $invocation->ok,
                ];
            });

        usort($events, fn (array $a, array $b): int => strcmp($b['occurred_at'], $a['occurred_at']));

        return array_slice($events, 0, $limit);
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

        $ledger = CreditLedgerEntry::query()
            ->whereIn('user_id', $userIds)
            ->selectRaw('user_id, MAX(created_at) as last_at')
            ->groupBy('user_id')
            ->pluck('last_at', 'user_id');

        $mcp = McpToolInvocation::query()
            ->whereIn('user_id', $userIds)
            ->selectRaw('user_id, MAX(created_at) as last_at')
            ->groupBy('user_id')
            ->pluck('last_at', 'user_id');

        $merged = [];

        foreach ($userIds as $userId) {
            $candidates = array_filter([
                $ledger[$userId] ?? null,
                $mcp[$userId] ?? null,
            ]);

            if ($candidates === []) {
                continue;
            }

            $latest = collect($candidates)->max();
            $merged[$userId] = Carbon::parse((string) $latest)->toIso8601String();
        }

        return $merged;
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

    private function normalizeSort(?string $sort): string
    {
        return in_array($sort, ['created_at', 'email', 'name', 'balance'], true)
            ? $sort
            : 'created_at';
    }

    private function normalizeDirection(?string $direction): string
    {
        return $direction === 'asc' ? 'asc' : 'desc';
    }

    private function normalizePlanFilter(?string $plan): ?string
    {
        return in_array($plan, ['subscribed', 'none'], true) ? $plan : null;
    }

    private function roundPence(float $pence): float
    {
        return round($pence, 2, PHP_ROUND_HALF_UP);
    }
}
