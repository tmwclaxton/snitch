<?php

namespace App\Services\Growth;

use App\Models\FollowerSnapshot;
use App\Models\MonthlyReport;
use App\Models\Post;
use App\Models\TrackedAccount;
use App\Models\User;
use App\Models\WeeklyBrief;
use App\Models\WinnerInsight;
use App\Services\Billing\PlanEntitlementService;
use App\Services\Dashboard\DashboardMath;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class MonthlyReportBuilder
{
    public function __construct(
        private DashboardMath $math,
        private PlanEntitlementService $entitlements,
        private GrowthMetricsBuilder $growth,
    ) {}

    public function monthStart(?string $month = null): CarbonImmutable
    {
        if (is_string($month) && preg_match('/^\d{4}-\d{2}$/', $month) === 1) {
            return CarbonImmutable::createFromFormat('Y-m', $month, DashboardMath::TIMEZONE)
                ->startOfMonth();
        }

        // Default to the current month (partial "so far").
        return CarbonImmutable::now(DashboardMath::TIMEZONE)->startOfMonth();
    }

    public function monthLabel(CarbonImmutable $monthStart): string
    {
        $now = CarbonImmutable::now(DashboardMath::TIMEZONE);
        $label = $monthStart->format('F Y');

        if ($monthStart->isSameMonth($now)) {
            return $label.' (so far)';
        }

        return $label;
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public function monthOptions(User $user, CarbonImmutable $selected): array
    {
        $now = CarbonImmutable::now(DashboardMath::TIMEZONE)->startOfMonth();
        $options = [];

        for ($i = 0; $i < 12; $i++) {
            $start = $now->subMonths($i);
            $options[$start->format('Y-m')] = [
                'value' => $start->format('Y-m'),
                'label' => $this->monthLabel($start),
            ];
        }

        $stored = MonthlyReport::query()
            ->where('user_id', $user->id)
            ->orderByDesc('month_start')
            ->limit(24)
            ->pluck('month_start');

        foreach ($stored as $day) {
            $start = CarbonImmutable::parse($day, DashboardMath::TIMEZONE)->startOfMonth();
            $key = $start->format('Y-m');
            $options[$key] = [
                'value' => $key,
                'label' => $this->monthLabel($start),
            ];
        }

        $selectedKey = $selected->format('Y-m');
        $options[$selectedKey] = [
            'value' => $selectedKey,
            'label' => $this->monthLabel($selected),
        ];

        krsort($options);

        return array_values($options);
    }

    /**
     * @return array<string, mixed>
     */
    public function build(User $user, CarbonImmutable $monthStart): array
    {
        $monthEnd = $monthStart->endOfMonth();
        $now = CarbonImmutable::now(DashboardMath::TIMEZONE);

        if ($monthStart->isSameMonth($now) && $monthEnd->gt($now)) {
            $monthEnd = $now->endOfDay();
        }

        $prevStart = $monthStart->subMonth()->startOfMonth();
        $prevEnd = $monthStart->subMonth()->endOfMonth();
        $prevLabel = $prevStart->format('F');

        $accounts = $this->growth->trackedAccounts($user);
        $own = $accounts->first(fn (TrackedAccount $a): bool => (bool) $a->is_own_account);
        $rivals = $accounts->filter(fn (TrackedAccount $a): bool => ! (bool) $a->is_own_account)->values();

        $kpis = $this->kpis($own, $rivals, $monthStart, $monthEnd, $prevStart, $prevEnd, $prevLabel);
        $ownTop = $own === null ? [] : $this->topPostsForAccount($own, $monthStart, $monthEnd, 3);
        $rivalWinners = $this->topRivalWinners($user, $rivals, $monthStart, $monthEnd, 3);
        $brief = WeeklyBrief::query()
            ->where('user_id', $user->id)
            ->where('status', 'ready')
            ->orderByDesc('week_start')
            ->with('ideas')
            ->first();

        $changed = $this->whatChanged($kpis);

        return [
            'month' => $monthStart->format('Y-m'),
            'month_label' => $this->monthLabel($monthStart),
            'prev_month_label' => $prevLabel,
            'generated_at' => now()->toIso8601String(),
            'kpis' => $kpis,
            'own_top_posts' => $ownTop,
            'competitor_winners' => $rivalWinners,
            'what_changed' => $changed,
            'next_focus' => $brief === null ? [] : $brief->ideas->take(3)->map(fn ($idea): array => [
                'format' => $idea->format,
                'hook' => $idea->hook,
                'why' => $idea->why,
            ])->values()->all(),
            'brief_week' => $brief?->week_start?->toDateString(),
        ];
    }

    public function persist(User $user, CarbonImmutable $monthStart): MonthlyReport
    {
        $payload = $this->build($user, $monthStart);

        return MonthlyReport::query()->updateOrCreate(
            [
                'user_id' => $user->id,
                'month_start' => $monthStart->toDateString(),
            ],
            ['payload' => $payload],
        );
    }

    /**
     * @param  Collection<int, TrackedAccount>  $rivals
     * @return array<string, mixed>
     */
    private function kpis(
        ?TrackedAccount $own,
        Collection $rivals,
        CarbonImmutable $monthStart,
        CarbonImmutable $monthEnd,
        CarbonImmutable $prevStart,
        CarbonImmutable $prevEnd,
        string $prevLabel,
    ): array {
        $ownNow = $this->accountMonthStats($own, $monthStart, $monthEnd);
        $ownPrev = $this->accountMonthStats($own, $prevStart, $prevEnd);

        $peerNow = $this->peerMonthStats($rivals, $monthStart, $monthEnd);
        $peerPrev = $this->peerMonthStats($rivals, $prevStart, $prevEnd);

        return [
            'followers' => $this->kpiPair('followers', $ownNow['followers'], $ownPrev['followers'], $peerNow['followers'], $peerPrev['followers'], $prevLabel),
            'posts' => $this->kpiPair('posts', $ownNow['posts'], $ownPrev['posts'], $peerNow['posts'], $peerPrev['posts'], $prevLabel),
            'engagement_rate' => $this->kpiPair('engagement_rate', $ownNow['engagement_rate'], $ownPrev['engagement_rate'], $peerNow['engagement_rate'], $peerPrev['engagement_rate'], $prevLabel),
            'avg_multiplier' => $this->kpiPair('avg_multiplier', $ownNow['avg_multiplier'], $ownPrev['avg_multiplier'], $peerNow['avg_multiplier'], $peerPrev['avg_multiplier'], $prevLabel),
        ];
    }

    /**
     * @return array{followers: int|null, posts: int, engagement_rate: float|null, avg_multiplier: float|null}
     */
    private function accountMonthStats(?TrackedAccount $account, CarbonImmutable $start, CarbonImmutable $end): array
    {
        if ($account === null || $account->social_account_id === null) {
            return [
                'followers' => null,
                'posts' => 0,
                'engagement_rate' => null,
                'avg_multiplier' => null,
            ];
        }

        $socialId = (int) $account->social_account_id;
        $posts = Post::query()
            ->where('social_account_id', $socialId)
            ->whereBetween('posted_at', [$start, $end])
            ->orderByDesc('posted_at')
            ->get();

        $snapshots = FollowerSnapshot::query()
            ->where('social_account_id', $socialId)
            ->orderBy('captured_on')
            ->get(['captured_on', 'followers']);

        $followersFallback = (int) ($account->followers ?? 0);
        $er = [];
        $mult = [];

        foreach ($posts as $post) {
            $followers = $this->math->followersAt(
                $snapshots->map(fn (FollowerSnapshot $s): array => [
                    'captured_on' => $s->captured_on?->toDateString() ?? '',
                    'followers' => (int) $s->followers,
                ]),
                $post->posted_at,
                $followersFallback > 0 ? $followersFallback : null,
            );
            $rate = $this->math->engagementRate($post, $followers);

            if ($rate !== null) {
                $er[] = $rate;
            }

            $pi = $this->math->performanceIndex($post, $posts)['pi'] ?? null;

            if ($pi !== null) {
                $mult[] = (float) $pi;
            }
        }

        $latestFollowers = $snapshots
            ->filter(fn (FollowerSnapshot $s): bool => $s->captured_on !== null
                && $s->captured_on->betweenIncluded($start, $end))
            ->last()?->followers;

        return [
            'followers' => $latestFollowers !== null
                ? (int) $latestFollowers
                : ($followersFallback > 0 ? $followersFallback : null),
            'posts' => $posts->count(),
            'engagement_rate' => $er === [] ? null : round(array_sum($er) / count($er), 2),
            'avg_multiplier' => $mult === [] ? null : round(array_sum($mult) / count($mult), 2),
        ];
    }

    /**
     * @param  Collection<int, TrackedAccount>  $rivals
     * @return array{followers: float|null, posts: float|null, engagement_rate: float|null, avg_multiplier: float|null}
     */
    private function peerMonthStats(Collection $rivals, CarbonImmutable $start, CarbonImmutable $end): array
    {
        if ($rivals->isEmpty()) {
            return [
                'followers' => null,
                'posts' => null,
                'engagement_rate' => null,
                'avg_multiplier' => null,
            ];
        }

        $rows = $rivals->map(fn (TrackedAccount $a): array => $this->accountMonthStats($a, $start, $end));

        return [
            'followers' => $this->math->median($rows->pluck('followers')->filter(fn ($v) => $v !== null)->values()),
            'posts' => $this->math->median($rows->pluck('posts')->values()),
            'engagement_rate' => $this->math->median($rows->pluck('engagement_rate')->filter(fn ($v) => $v !== null)->values()),
            'avg_multiplier' => $this->math->median($rows->pluck('avg_multiplier')->filter(fn ($v) => $v !== null)->values()),
        ];
    }

    /**
     * @return array{
     *     you: float|int|null,
     *     you_display: string,
     *     you_prev: float|int|null,
     *     you_change: float|null,
     *     you_change_label: string,
     *     peer: float|null,
     *     peer_display: string,
     *     peer_prev: float|null,
     *     peer_change: float|null,
     *     peer_change_label: string
     * }
     */
    private function kpiPair(
        string $key,
        float|int|null $you,
        float|int|null $youPrev,
        float|int|null $peer,
        float|int|null $peerPrev,
        string $prevLabel,
    ): array {
        $youChange = $this->change($you, $youPrev);
        $peerChange = $this->change($peer, $peerPrev);

        return [
            'you' => $you,
            'you_display' => $this->formatKpiValue($key, $you),
            'you_prev' => $youPrev,
            'you_change' => $youChange,
            'you_change_label' => $youChange === null
                ? 'no data for '.$prevLabel
                : (($youChange > 0 ? '+' : '').$youChange.'%'),
            'peer' => $peer === null ? null : (is_float($peer) ? round($peer, 2) : $peer),
            'peer_display' => $this->formatKpiValue($key, $peer),
            'peer_prev' => $peerPrev,
            'peer_change' => $peerChange,
            'peer_change_label' => $peerChange === null
                ? 'no data for '.$prevLabel
                : (($peerChange > 0 ? '+' : '').$peerChange.'%'),
        ];
    }

    private function formatKpiValue(string $key, float|int|null $value): string
    {
        if ($value === null) {
            return 'no data';
        }

        return match ($key) {
            'engagement_rate' => number_format((float) $value, 1).'%',
            'avg_multiplier' => number_format((float) $value, 2).'×',
            'followers', 'posts' => number_format((float) $value),
            default => (string) $value,
        };
    }

    private function change(float|int|null $now, float|int|null $prev): ?float
    {
        if ($now === null || $prev === null || (float) $prev == 0.0) {
            return null;
        }

        return round((((float) $now - (float) $prev) / (float) $prev) * 100, 1);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function topPostsForAccount(TrackedAccount $account, CarbonImmutable $start, CarbonImmutable $end, int $limit): array
    {
        $posts = Post::query()
            ->where('social_account_id', $account->social_account_id)
            ->whereBetween('posted_at', [$start, $end])
            ->orderByDesc('posted_at')
            ->get();

        return $posts
            ->map(function (Post $post) use ($posts): array {
                $pi = $this->math->performanceIndex($post, $posts)['pi'] ?? null;
                $caption = trim((string) ($post->caption ?? ''));

                return [
                    'id' => $post->id,
                    'caption' => $caption,
                    'caption_preview' => $this->clampCaption($caption, 160),
                    'url' => $post->url,
                    'thumbnail_url' => $post->cover_url,
                    'posted_at' => $post->posted_at?->toIso8601String(),
                    'metrics' => $post->metrics,
                    'multiplier' => $pi === null ? null : round((float) $pi, 2),
                ];
            })
            ->sortByDesc(fn (array $row) => (float) ($row['multiplier'] ?? 0))
            ->take($limit)
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, TrackedAccount>  $rivals
     * @return list<array<string, mixed>>
     */
    private function topRivalWinners(
        User $user,
        Collection $rivals,
        CarbonImmutable $start,
        CarbonImmutable $end,
        int $limit,
    ): array {
        $socialIds = $rivals->pluck('social_account_id')->filter()->map(fn ($id) => (int) $id)->all();

        if ($socialIds === []) {
            return [];
        }

        return WinnerInsight::query()
            ->where('user_id', $user->id)
            ->whereHas('post', function ($query) use ($socialIds, $start, $end): void {
                $query->whereIn('social_account_id', $socialIds)
                    ->whereBetween('posted_at', [$start, $end]);
            })
            ->with(['post.socialAccount'])
            ->orderByDesc('performance_multiplier')
            ->limit($limit)
            ->get()
            ->map(function (WinnerInsight $insight): array {
                $post = $insight->post;
                $caption = trim((string) ($post?->caption ?? ''));

                return [
                    'id' => $post?->id,
                    'handle' => $post?->socialAccount?->handle,
                    'caption' => $caption,
                    'caption_preview' => $this->clampCaption($caption, 160),
                    'url' => $post?->url,
                    'thumbnail_url' => $post?->cover_url,
                    'multiplier' => $insight->performance_multiplier !== null
                        ? round((float) $insight->performance_multiplier, 2)
                        : null,
                    'why' => $insight->why,
                ];
            })
            ->all();
    }

    private function clampCaption(string $caption, int $maxChars): string
    {
        if ($caption === '' || mb_strlen($caption) <= $maxChars) {
            return $caption;
        }

        $slice = mb_substr($caption, 0, $maxChars);
        $break = mb_strrpos($slice, ' ');

        if ($break !== false && $break > (int) ($maxChars * 0.6)) {
            $slice = mb_substr($slice, 0, $break);
        }

        return rtrim($slice, " \t\n\r\0\x0B.,;:").' more';
    }

    /**
     * @param  array<string, mixed>  $kpis
     * @return list<string>
     */
    private function whatChanged(array $kpis): array
    {
        $lines = [];

        foreach ([
            'followers' => 'Followers',
            'posts' => 'Posts',
            'engagement_rate' => 'Engagement rate',
            'avg_multiplier' => 'Average X× usual',
        ] as $key => $label) {
            $change = $kpis[$key]['you_change'] ?? null;

            if ($change === null) {
                continue;
            }

            $direction = $change >= 0 ? 'up' : 'down';
            $lines[] = "{$label} {$direction} ".abs((float) $change).'% vs last month.';
        }

        if ($lines === []) {
            $lines[] = 'Not enough history yet to compare with last month.';
        }

        return $lines;
    }
}
