<?php

namespace App\Services\Growth;

use App\Models\FollowerSnapshot;
use App\Models\Post;
use App\Models\TrackedAccount;
use App\Models\User;
use App\Services\Billing\PlanEntitlementService;
use App\Services\Dashboard\DashboardMath;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class GrowthMetricsBuilder
{
    public function __construct(
        private DashboardMath $math,
        private PlanEntitlementService $entitlements,
    ) {}

    /**
     * @param  list<int>  $selectedTrackedIds
     * @return array{
     *     period: string,
     *     snapshot_count: int,
     *     thin_data: bool,
     *     note: string|null,
     *     accounts: list<array<string, mixed>>,
     *     peer_median: array<string, mixed>,
     *     charts: array{
     *         followers: list<array<string, mixed>>,
     *         posts_per_week: list<array<string, mixed>>,
     *         engagement_rate: list<array<string, mixed>>,
     *         avg_multiplier: list<array<string, mixed>>
     *     }
     * }
     */
    public function build(User $user, string $period = '30d', array $selectedTrackedIds = []): array
    {
        $range = $this->rangeFor($period);
        $accounts = $this->trackedAccounts($user, $selectedTrackedIds);

        $series = collect();
        $snapshotCount = 0;

        foreach ($accounts as $account) {
            $row = $this->accountSeries($account, $range['start'], $range['end']);
            $snapshotCount = max($snapshotCount, (int) ($row['snapshot_count'] ?? 0));
            $series->push($row);
        }

        $peer = $this->peerMedianSeries(
            $series->filter(fn (array $row): bool => ! ($row['is_own_account'] ?? false))->values(),
        );

        $thin = $snapshotCount < 2;

        return [
            'period' => $period,
            'snapshot_count' => $snapshotCount,
            'thin_data' => $thin,
            'note' => $thin
                ? ($snapshotCount === 1
                    ? 'Only one weekly snapshot so far. Charts fill in as Monday syncs land.'
                    : 'No weekly follower snapshots yet. Charts fill in after the first Monday sync.')
                : null,
            'accounts' => $series->values()->all(),
            'peer_median' => $peer,
            'charts' => [
                'followers' => $this->chartFrom($series, $peer, 'followers'),
                'posts_per_week' => $this->chartFrom($series, $peer, 'posts_per_week'),
                'engagement_rate' => $this->chartFrom($series, $peer, 'engagement_rate'),
                'avg_multiplier' => $this->chartFrom($series, $peer, 'avg_multiplier'),
            ],
        ];
    }

    /**
     * @return Collection<int, TrackedAccount>
     */
    public function trackedAccounts(User $user, array $selectedTrackedIds = []): Collection
    {
        $ids = $this->entitlements->inQuotaTrackedAccountIds($user);

        $query = TrackedAccount::query()
            ->where('user_id', $user->id)
            ->whereIn('id', $ids === [] ? [0] : $ids)
            ->with('socialAccount')
            ->orderByDesc('is_own_account')
            ->orderBy('handle');

        $all = $query->get();

        if ($selectedTrackedIds === []) {
            return $all;
        }

        $selected = array_map('intval', $selectedTrackedIds);

        return $all->filter(function (TrackedAccount $account) use ($selected): bool {
            return (bool) $account->is_own_account || in_array((int) $account->id, $selected, true);
        })->values();
    }

    /**
     * @return array{start: CarbonImmutable|null, end: CarbonImmutable}
     */
    private function rangeFor(string $period): array
    {
        $end = CarbonImmutable::now(DashboardMath::TIMEZONE)->endOfDay();

        return match ($period) {
            '90d' => ['start' => $end->subDays(90)->startOfDay(), 'end' => $end],
            'all' => ['start' => null, 'end' => $end],
            default => ['start' => $end->subDays(30)->startOfDay(), 'end' => $end],
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function accountSeries(
        TrackedAccount $account,
        ?CarbonImmutable $start,
        CarbonImmutable $end,
    ): array {
        $socialId = (int) $account->social_account_id;

        $snapshots = FollowerSnapshot::query()
            ->where('social_account_id', $socialId)
            ->when($start !== null, fn ($q) => $q->whereDate('captured_on', '>=', $start->toDateString()))
            ->whereDate('captured_on', '<=', $end->toDateString())
            ->orderBy('captured_on')
            ->get(['captured_on', 'followers']);

        $followerPoints = $snapshots->map(fn (FollowerSnapshot $row): array => [
            'date' => $row->captured_on?->toDateString(),
            'value' => (int) $row->followers,
        ])->values()->all();

        $posts = Post::query()
            ->where('social_account_id', $socialId)
            ->when($start !== null, fn ($q) => $q->where('posted_at', '>=', $start))
            ->where('posted_at', '<=', $end)
            ->orderByDesc('posted_at')
            ->get();

        $weeks = max(1, ($start === null
            ? max(1, (int) ceil(max(1, $posts->min('posted_at')?->diffInDays($end) ?? 7) / 7))
            : (int) ceil($start->diffInDays($end) / 7)));

        $postsPerWeek = $posts->count() / $weeks;

        $followersFallback = (int) ($account->followers ?? 0);
        $erValues = [];
        $multipliers = [];

        foreach ($posts as $post) {
            $followers = $this->math->followersAt(
                $snapshots->map(fn (FollowerSnapshot $s): array => [
                    'captured_on' => $s->captured_on?->toDateString() ?? '',
                    'followers' => (int) $s->followers,
                ]),
                $post->posted_at,
                $followersFallback > 0 ? $followersFallback : null,
            );
            $er = $this->math->engagementRate($post, $followers);

            if ($er !== null) {
                $erValues[] = $er;
            }

            $pi = $this->math->performanceIndex($post, $posts)['pi'] ?? null;

            if ($pi !== null) {
                $multipliers[] = (float) $pi;
            }
        }

        $avgEr = $erValues === [] ? null : array_sum($erValues) / count($erValues);
        $avgMultiplier = $multipliers === [] ? null : array_sum($multipliers) / count($multipliers);

        // Weekly buckets for dense charts (avoid empty white gaps).
        $weekStarts = $this->weekStarts($start, $end, $posts, $snapshots);
        $ppwPoints = [];
        $erPoints = [];
        $multPoints = [];

        foreach ($weekStarts as $weekStart) {
            $weekEnd = $weekStart->endOfWeek(CarbonImmutable::SUNDAY);
            $weekPosts = $posts->filter(function (Post $post) use ($weekStart, $weekEnd): bool {
                if ($post->posted_at === null) {
                    return false;
                }

                return $post->posted_at->betweenIncluded($weekStart, $weekEnd);
            });

            $ppwPoints[] = [
                'date' => $weekStart->toDateString(),
                // Null (gap) when nobody posted that week - avoids a false plunge to zero.
                'value' => $weekPosts->isEmpty() ? null : (float) $weekPosts->count(),
            ];

            $weekEr = [];
            $weekMult = [];

            foreach ($weekPosts as $post) {
                $followers = $this->math->followersAt(
                    $snapshots->map(fn (FollowerSnapshot $s): array => [
                        'captured_on' => $s->captured_on?->toDateString() ?? '',
                        'followers' => (int) $s->followers,
                    ]),
                    $post->posted_at,
                    $followersFallback > 0 ? $followersFallback : null,
                );
                $er = $this->math->engagementRate($post, $followers);

                if ($er !== null) {
                    $weekEr[] = $er;
                }

                $pi = $this->math->performanceIndex($post, $posts)['pi'] ?? null;

                if ($pi !== null) {
                    $weekMult[] = (float) $pi;
                }
            }

            $erPoints[] = [
                'date' => $weekStart->toDateString(),
                'value' => $weekEr === [] ? null : round(array_sum($weekEr) / count($weekEr), 2),
            ];
            $multPoints[] = [
                'date' => $weekStart->toDateString(),
                'value' => $weekMult === [] ? null : round(array_sum($weekMult) / count($weekMult), 2),
            ];
        }

        return [
            'tracked_account_id' => (int) $account->id,
            'handle' => (string) $account->handle,
            'is_own_account' => (bool) $account->is_own_account,
            'snapshot_count' => $snapshots->count(),
            'summary' => [
                'followers' => $snapshots->last()?->followers ?? ($followersFallback > 0 ? $followersFallback : null),
                'posts_per_week' => round($postsPerWeek, 2),
                'engagement_rate' => $avgEr === null ? null : round($avgEr, 2),
                'avg_multiplier' => $avgMultiplier === null ? null : round($avgMultiplier, 2),
            ],
            'followers' => $followerPoints,
            'posts_per_week' => $ppwPoints,
            'engagement_rate' => $erPoints,
            'avg_multiplier' => $multPoints,
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $peerRows
     * @return array<string, mixed>
     */
    private function peerMedianSeries(Collection $peerRows): array
    {
        if ($peerRows->isEmpty()) {
            return [
                'handle' => 'Peer median',
                'is_own_account' => false,
                'is_peer_median' => true,
                'snapshot_count' => 0,
                'summary' => [
                    'followers' => null,
                    'posts_per_week' => null,
                    'engagement_rate' => null,
                    'avg_multiplier' => null,
                ],
                'followers' => [],
                'posts_per_week' => [],
                'engagement_rate' => [],
                'avg_multiplier' => [],
            ];
        }

        $keys = ['followers', 'posts_per_week', 'engagement_rate', 'avg_multiplier'];
        $out = [
            'handle' => 'Peer median',
            'is_own_account' => false,
            'is_peer_median' => true,
            'snapshot_count' => (int) $peerRows->max('snapshot_count'),
            'summary' => [],
        ];

        foreach ($keys as $key) {
            $out['summary'][$key] = $this->math->median(
                $peerRows->pluck("summary.{$key}")->filter(fn ($v) => $v !== null)->values(),
            );

            $dates = $peerRows
                ->flatMap(fn (array $row) => collect($row[$key] ?? [])->pluck('date'))
                ->filter()
                ->unique()
                ->sort()
                ->values();

            $points = [];

            foreach ($dates as $date) {
                $values = [];

                foreach ($peerRows as $row) {
                    $match = collect($row[$key] ?? [])->firstWhere('date', $date);
                    if (is_array($match) && $match['value'] !== null) {
                        $values[] = (float) $match['value'];
                    }
                }

                $points[] = [
                    'date' => $date,
                    'value' => $this->math->median($values),
                ];
            }

            $out[$key] = $points;
        }

        return $out;
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $series
     * @param  array<string, mixed>  $peer
     * @return list<array{name: string, is_own_account: bool, is_peer_median: bool, points: list<array{date: string, value: float|null}>}>
     */
    private function chartFrom(Collection $series, array $peer, string $key): array
    {
        $rows = $series->map(fn (array $row): array => [
            'name' => ($row['is_own_account'] ?? false) ? 'You' : '@'.$row['handle'],
            'is_own_account' => (bool) ($row['is_own_account'] ?? false),
            'is_peer_median' => false,
            'points' => $row[$key] ?? [],
        ])->values()->all();

        $rows[] = [
            'name' => 'Peer median',
            'is_own_account' => false,
            'is_peer_median' => true,
            'points' => $peer[$key] ?? [],
        ];

        return $rows;
    }

    /**
     * @param  Collection<int, Post>  $posts
     * @param  Collection<int, FollowerSnapshot>  $snapshots
     * @return list<CarbonImmutable>
     */
    private function weekStarts(
        ?CarbonImmutable $start,
        CarbonImmutable $end,
        Collection $posts,
        Collection $snapshots,
    ): array {
        $first = $start;

        if ($first === null) {
            $postFirst = $posts->min('posted_at');
            $snapFirst = $snapshots->min('captured_on');
            $candidates = array_filter([
                $postFirst instanceof \DateTimeInterface ? CarbonImmutable::instance($postFirst) : null,
                $snapFirst instanceof \DateTimeInterface ? CarbonImmutable::instance($snapFirst) : null,
            ]);
            $first = $candidates === []
                ? $end->subWeeks(4)
                : collect($candidates)->min();
        }

        $cursor = $first->timezone(DashboardMath::TIMEZONE)->startOfWeek(CarbonImmutable::MONDAY);
        // Drop the in-progress week so posts-per-week does not plunge to a partial count.
        $completeEnd = $end->timezone(DashboardMath::TIMEZONE)
            ->startOfWeek(CarbonImmutable::MONDAY)
            ->subWeek();
        $weeks = [];

        while ($cursor->lte($completeEnd)) {
            $weeks[] = $cursor;
            $cursor = $cursor->addWeek();
        }

        return $weeks;
    }
}
