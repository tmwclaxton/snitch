<?php

namespace App\Services\Growth;

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
use Illuminate\Support\Str;

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

        return CarbonImmutable::now(DashboardMath::TIMEZONE)->startOfMonth()->subMonth();
    }

    /**
     * @return array<string, mixed>
     */
    public function build(User $user, CarbonImmutable $monthStart): array
    {
        $monthEnd = $monthStart->endOfMonth();
        $prevStart = $monthStart->subMonth()->startOfMonth();
        $prevEnd = $monthStart->subMonth()->endOfMonth();

        $accounts = $this->growth->trackedAccounts($user);
        $own = $accounts->first(fn (TrackedAccount $a): bool => (bool) $a->is_own_account);
        $rivals = $accounts->filter(fn (TrackedAccount $a): bool => ! (bool) $a->is_own_account)->values();

        $kpis = $this->kpis($own, $rivals, $monthStart, $monthEnd, $prevStart, $prevEnd);
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
            'month_label' => $monthStart->format('F Y'),
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
    ): array {
        $ownNow = $this->accountMonthStats($own, $monthStart, $monthEnd);
        $ownPrev = $this->accountMonthStats($own, $prevStart, $prevEnd);

        $peerNow = $this->peerMonthStats($rivals, $monthStart, $monthEnd);
        $peerPrev = $this->peerMonthStats($rivals, $prevStart, $prevEnd);

        return [
            'followers' => $this->kpiPair($ownNow['followers'], $ownPrev['followers'], $peerNow['followers'], $peerPrev['followers']),
            'posts' => $this->kpiPair($ownNow['posts'], $ownPrev['posts'], $peerNow['posts'], $peerPrev['posts']),
            'engagement_rate' => $this->kpiPair($ownNow['engagement_rate'], $ownPrev['engagement_rate'], $peerNow['engagement_rate'], $peerPrev['engagement_rate']),
            'avg_multiplier' => $this->kpiPair($ownNow['avg_multiplier'], $ownPrev['avg_multiplier'], $peerNow['avg_multiplier'], $peerPrev['avg_multiplier']),
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

        $followers = (int) ($account->followers ?? 0);
        $er = [];
        $mult = [];

        foreach ($posts as $post) {
            $rate = $this->math->engagementRate($post, $followers > 0 ? $followers : null);

            if ($rate !== null) {
                $er[] = $rate;
            }

            $pi = $this->math->performanceIndex($post, $posts)['pi'] ?? null;

            if ($pi !== null) {
                $mult[] = (float) $pi;
            }
        }

        return [
            'followers' => $followers > 0 ? $followers : null,
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
     * @return array{you: float|int|null, you_prev: float|int|null, you_change: float|null, peer: float|null, peer_prev: float|null, peer_change: float|null}
     */
    private function kpiPair(float|int|null $you, float|int|null $youPrev, float|int|null $peer, float|int|null $peerPrev): array
    {
        return [
            'you' => $you,
            'you_prev' => $youPrev,
            'you_change' => $this->change($you, $youPrev),
            'peer' => $peer,
            'peer_prev' => $peerPrev,
            'peer_change' => $this->change($peer, $peerPrev),
        ];
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

                return [
                    'id' => $post->id,
                    'caption' => Str::limit((string) $post->caption, 120),
                    'url' => $post->url,
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

                return [
                    'id' => $post?->id,
                    'handle' => $post?->socialAccount?->handle,
                    'caption' => Str::limit((string) ($post?->caption ?? ''), 120),
                    'url' => $post?->url,
                    'multiplier' => $insight->performance_multiplier !== null
                        ? round((float) $insight->performance_multiplier, 2)
                        : null,
                    'why' => $insight->why,
                ];
            })
            ->all();
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
