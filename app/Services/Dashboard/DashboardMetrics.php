<?php

namespace App\Services\Dashboard;

use App\Enums\Platform;
use App\Enums\PostType;
use App\Models\FollowerSnapshot;
use App\Models\Post;
use App\Models\TrackedAccount;
use App\Models\User;
use App\Services\Analysis\AnalysisTermCatalogue;
use App\Services\Competitors\CompetitorInsightsBuilder;
use App\Support\PostAccountPresenter;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class DashboardMetrics
{
    public const MAX_COMPARE = 4;

    public const PERIODS = [7, 30, 90];

    public const RECENT_POST_FRAMES = 24;

    public function __construct(
        private DashboardMath $math,
        private InsightRules $insightRules,
        private DashboardCache $cache,
        private DashboardActivityBuilder $activity,
        private CompetitorInsightsBuilder $competitorInsights,
        private AnalysisTermCatalogue $catalogue,
    ) {}

    /**
     * @param  list<string>  $selectedHandles
     * @return array<string, mixed>
     */
    public function forUser(User $user, array $selectedHandles = [], int $periodDays = 30, bool $showHiddenLikes = false): array
    {
        $periodDays = in_array($periodDays, self::PERIODS, true) ? $periodDays : 30;

        return $this->cache->remember(
            $user,
            (string) $periodDays,
            $selectedHandles,
            fn (): array => $this->build($user, $selectedHandles, $periodDays, $showHiddenLikes),
            $showHiddenLikes,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function emptyPayload(): array
    {
        return [
            'period' => 30,
            'periods' => self::PERIODS,
            'timezone' => DashboardMath::TIMEZONE,
            'own_account' => null,
            'rivals' => [],
            'selected' => [],
            'max_compare' => self::MAX_COMPARE,
            'legacy_non_instagram_count' => 0,
            'controls' => [
                'last_refreshed_at' => null,
                'next_refresh_at' => null,
                'has_non_instagram_trackers' => false,
                'hidden_likes_count' => 0,
            ],
            'onboarding' => CardResult::ok([
                'steps' => [
                    ['key' => 'own', 'label' => 'Add your own Instagram', 'done' => false],
                    ['key' => 'rivals', 'label' => 'Add Instagram competitors on Tracking', 'done' => false, 'suggestions' => $this->suggestedHandles()],
                    ['key' => 'refresh', 'label' => 'First refresh running - data after the next sync', 'done' => false],
                ],
                'note' => null,
            ], 0),
            'insights' => CardResult::empty('We\'ll write your first insights after the first refresh (need at least 5 posts from 2 accounts).'),
            'kpis' => CardResult::empty('Add Instagram competitors to see KPIs.'),
            'rail' => [
                'cells' => [],
                'ready' => false,
            ],
            'leaderboard' => CardResult::empty('Add Instagram competitors to compare.'),
            'winners' => CardResult::empty('No standout posts this period.'),
            'growth_series' => CardResult::empty('Not built yet.'),
            'efficiency' => CardResult::empty('Not built yet.'),
            'format_mix' => CardResult::empty('Not built yet.'),
            'format_lift' => CardResult::empty('Not built yet.'),
            'heatmap' => CardResult::empty('Not built yet.'),
            'show_hidden_likes' => false,
            'captions' => CardResult::empty('Not built yet.'),
            'themes' => CardResult::empty('Not built yet.'),
            'weekly' => CardResult::empty('Not built yet.'),
            'attention' => CardResult::empty('Not built yet.'),
            'actions' => CardResult::empty('Not built yet.'),
            'data_notes' => CardResult::empty('Not built yet.'),
            'activity' => [
                'heatmap' => [],
                'weekly' => [],
                'by_time_of_day' => [],
                'window_start' => null,
                'window_end' => null,
            ],
            'follower_series' => [],
            'growth_delta' => [
                'followers' => 0,
                'week_delta' => null,
                'week_pct' => null,
            ],
            'recent_posts' => [],
            'caption_intel' => [
                'hashtags' => [],
                'keywords' => [],
                'ctas' => [],
                'cta_clicks' => ['posts_with_cta' => 0, 'posts' => 0],
                'format_mix' => [],
            ],
        ];
    }

    /**
     * @param  list<string>  $selectedHandles
     * @return array<string, mixed>
     */
    private function build(User $user, array $selectedHandles, int $periodDays, bool $showHiddenLikes = false): array
    {
        $allTrackers = $user->trackedAccounts()
            ->competitors()
            ->with(['socialAccount:id,avatar'])
            ->orderBy('id')
            ->get();
        $accounts = $allTrackers
            ->filter(fn (TrackedAccount $a): bool => $a->platform === Platform::Instagram)
            ->values();
        $nonIgCount = $allTrackers
            ->reject(fn (TrackedAccount $a): bool => $a->platform === Platform::Instagram)
            ->count();

        $own = $accounts->firstWhere('is_own_account', true);
        $rivals = $accounts->filter(fn (TrackedAccount $a): bool => ! $a->is_own_account)->values();

        $since = CarbonImmutable::now('UTC')->subDays($periodDays);
        $historySince = CarbonImmutable::now('UTC')->subDays(max($periodDays, 90));

        // Load posts for every Instagram competitor so default selection can
        // prefer rivals with data, and empty rivals still appear in the chips.
        $allSocialIds = $accounts->pluck('social_account_id')->filter()->all();
        $allPosts = $allSocialIds === []
            ? collect()
            : Post::query()
                ->whereIn('social_account_id', $allSocialIds)
                ->whereNotNull('posted_at')
                ->where('posted_at', '>=', $historySince)
                ->orderByDesc('posted_at')
                ->get();
        $allPosts = $this->math->dedupePosts($allPosts);

        $periodPostCounts = $allPosts
            ->filter(fn (Post $post): bool => $post->posted_at !== null && $post->posted_at->gte($since))
            ->countBy('social_account_id');

        $validSelected = $this->resolveSelected($rivals, $selectedHandles, $periodPostCounts);
        $selectedAccounts = $rivals->filter(
            fn (TrackedAccount $a): bool => $validSelected->contains(strtolower((string) $a->handle)),
        )->values();

        $visibleAccounts = collect([$own])->filter()->merge($selectedAccounts)->values();
        $socialIds = $visibleAccounts->pluck('social_account_id')->filter()->all();

        $posts = $allPosts->filter(
            fn (Post $post): bool => in_array($post->social_account_id, $socialIds, true),
        )->values();
        $snapshots = $this->loadSnapshots($socialIds);

        $enrichedAll = $this->enrichPosts($posts, $visibleAccounts, $snapshots);
        $periodPosts = $enrichedAll->filter(
            fn (array $row): bool => $row['posted_at'] !== null && $row['posted_at']->gte($since),
        )->values();

        $onboarding = $this->onboardingCard($own, $rivals, $periodPosts, $nonIgCount);
        $ready = ($onboarding['status'] ?? '') !== 'ok' || ($onboarding['data']['hide'] ?? false);

        $accountRows = $this->accountRows($visibleAccounts, $enrichedAll, $periodPosts, $snapshots, $periodDays);
        $peerRows = $accountRows->filter(fn (array $row): bool => ! $row['is_own_account'])->values();
        $ownRow = $accountRows->firstWhere('is_own_account', true);

        $insightContext = $this->insightContext($ownRow, $peerRows, $periodPosts, $enrichedAll);
        $insights = $this->insightsCard($insightContext, $peerRows, $periodPosts);
        $kpis = $this->kpisCard($ownRow, $peerRows, $enrichedAll, $periodDays);
        $leaderboard = $this->leaderboardCard($accountRows, $periodPosts);
        $winners = $this->winnersCard($periodPosts, $showHiddenLikes);
        $growthSeries = $this->growthSeriesCard($visibleAccounts, $snapshots);
        $efficiency = $this->efficiencyCard($accountRows);
        $formatMix = $this->formatMixCard($accountRows, $periodPosts);
        $formatLift = $this->formatLiftCard($accountRows, $periodPosts);
        $heatmap = $this->heatmapCard($periodPosts);
        $lastSynced = $accounts
            ->map(fn (TrackedAccount $a) => $a->last_synced_at)
            ->filter()
            ->max();

        $captions = $this->captionsCard($periodPosts, $accountRows);
        $themes = $this->themesCard($periodPosts, $accountRows);
        $weekly = $this->weeklyCard($accountRows, $enrichedAll, $snapshots);
        $attention = $this->attentionCard($accountRows);
        $actions = $this->actionsCard($insightContext, $ownRow);
        $dataNotes = $this->dataNotesCard($accountRows, $periodPosts, $periodDays, $lastSynced);

        $activity = $this->activity->forSocialAccounts(array_map('intval', $socialIds));
        // Drop platform split - dashboard is Instagram-only.
        unset($activity['by_platform']);

        $followerSeries = $this->competitorInsights->followerSeriesForIds(array_map('intval', $socialIds));
        $growthDelta = $this->growthDeltaFromSnapshots($visibleAccounts, $snapshots);
        $captionIntel = $this->captionIntelForAccounts($user, $socialIds, $since);
        $recentPosts = $this->recentPostsPayload(
            $user,
            $visibleAccounts,
            self::RECENT_POST_FRAMES,
            $showHiddenLikes,
        );
        $rail = $this->railCard(
            $visibleAccounts,
            $ownRow,
            $peerRows,
            $periodPosts,
            $winners,
            $growthDelta,
            $captionIntel,
            $lastSynced,
            $periodDays,
        );

        return [
            'period' => $periodDays,
            'periods' => self::PERIODS,
            'timezone' => DashboardMath::TIMEZONE,
            'own_account' => $own === null ? null : $this->accountPayload($own, $allPosts),
            'rivals' => $rivals->map(function (TrackedAccount $a) use ($allPosts, $periodPostCounts): array {
                $payload = $this->accountPayload($a, $allPosts);
                $periodCount = (int) ($periodPostCounts[$a->social_account_id] ?? 0);
                $payload['period_posts_count'] = $periodCount;
                $payload['no_posts_in_period'] = $periodCount === 0;

                return $payload;
            })->values()->all(),
            'selected' => $validSelected->all(),
            'max_compare' => self::MAX_COMPARE,
            'legacy_non_instagram_count' => $nonIgCount,
            'show_hidden_likes' => $showHiddenLikes,
            'controls' => [
                'last_refreshed_at' => $lastSynced?->timezone(DashboardMath::TIMEZONE)->toIso8601String(),
                'next_refresh_at' => CarbonImmutable::now(DashboardMath::TIMEZONE)
                    ->next('Monday')
                    ->setTime(8, 0)
                    ->toIso8601String(),
                'has_non_instagram_trackers' => $nonIgCount > 0,
                'ready' => $ready,
                'hidden_likes_count' => $periodPosts->filter(fn (array $row): bool => $row['hidden_likes'])->count(),
            ],
            'onboarding' => $onboarding,
            'insights' => $insights,
            'kpis' => $kpis,
            'rail' => $rail,
            'leaderboard' => $leaderboard,
            'winners' => $winners,
            'growth_series' => $growthSeries,
            'efficiency' => $efficiency,
            'format_mix' => $formatMix,
            'format_lift' => $formatLift,
            'heatmap' => $heatmap,
            'captions' => $captions,
            'themes' => $themes,
            'weekly' => $weekly,
            'attention' => $attention,
            'actions' => $actions,
            'data_notes' => $dataNotes,
            'activity' => $activity,
            'follower_series' => $followerSeries,
            'growth_delta' => $growthDelta,
            'recent_posts' => $recentPosts,
            'caption_intel' => $captionIntel,
        ];
    }

    /**
     * @param  Collection<int, TrackedAccount>  $rivals
     * @param  list<string>  $selectedHandles
     * @param  Collection<int|string, int>  $periodPostCounts
     * @return Collection<int, string>
     */
    private function resolveSelected(Collection $rivals, array $selectedHandles, Collection $periodPostCounts): Collection
    {
        $normalized = collect($selectedHandles)
            ->map(fn (string $handle): string => strtolower(ltrim(trim($handle), '@')))
            ->filter()
            ->unique()
            ->values();

        $valid = $normalized
            ->filter(fn (string $handle): bool => $rivals->contains(
                fn (TrackedAccount $account): bool => strtolower((string) $account->handle) === $handle,
            ))
            ->take(self::MAX_COMPARE)
            ->values();

        if ($valid->isEmpty()) {
            // Prefer rivals with posts in the period; never default-select empties
            // when denser accounts exist.
            return $rivals
                ->sortByDesc(fn (TrackedAccount $account): int => (int) ($periodPostCounts[$account->social_account_id] ?? 0))
                ->filter(fn (TrackedAccount $account): bool => (int) ($periodPostCounts[$account->social_account_id] ?? 0) > 0)
                ->take(self::MAX_COMPARE)
                ->map(fn (TrackedAccount $account): string => strtolower((string) $account->handle))
                ->values()
                ->whenEmpty(fn () => $rivals->take(self::MAX_COMPARE)
                    ->map(fn (TrackedAccount $account): string => strtolower((string) $account->handle))
                    ->values());
        }

        return $valid;
    }

    /**
     * @param  list<int>  $socialIds
     * @return Collection<int, Collection<int, array{captured_on: string, followers: int}>>
     */
    private function loadSnapshots(array $socialIds): Collection
    {
        if ($socialIds === []) {
            return collect();
        }

        return FollowerSnapshot::query()
            ->whereIn('social_account_id', $socialIds)
            ->orderBy('captured_on')
            ->get()
            ->groupBy('social_account_id')
            ->map(fn (Collection $rows): Collection => $rows->map(fn ($row): array => [
                'captured_on' => $row->captured_on?->toDateString() ?? (string) $row->captured_on,
                'followers' => (int) $row->followers,
            ])->values());
    }

    /**
     * @param  Collection<int, Post>  $posts
     * @param  Collection<int, TrackedAccount>  $accounts
     * @param  Collection<int, Collection<int, array{captured_on: string, followers: int}>>  $snapshots
     * @return Collection<int, array<string, mixed>>
     */
    private function enrichPosts(Collection $posts, Collection $accounts, Collection $snapshots): Collection
    {
        $bySocial = $accounts->keyBy('social_account_id');
        $postsByAccount = $posts->groupBy('social_account_id');

        return $posts->map(function (Post $post) use ($bySocial, $postsByAccount, $snapshots): array {
            /** @var TrackedAccount|null $account */
            $account = $bySocial->get($post->social_account_id);
            $accountPosts = $postsByAccount->get($post->social_account_id, collect());
            $snap = $snapshots->get($post->social_account_id, collect());
            $followers = $this->math->followersAt(
                $snap,
                $post->posted_at,
                $account?->followers,
            );
            $piMeta = $this->math->performanceIndex($post, $accountPosts);
            $bucket = $this->math->londonBucket($post->posted_at);
            $er = $this->math->engagementRate($post, $followers);
            $london = $this->math->toLondon($post->posted_at);

            return [
                'id' => $post->id,
                'social_account_id' => (int) $post->social_account_id,
                'tracked_account_id' => $account?->id,
                'handle' => $account?->handle,
                'is_own_account' => (bool) ($account?->is_own_account),
                'posted_at' => $post->posted_at,
                'london_at' => $london,
                'format' => $this->math->formatLabel($post),
                'likes' => $this->math->likes($post),
                'comments' => $this->math->comments($post),
                'views' => $this->math->views($post),
                'interactions' => $this->math->interactions($post),
                'followers' => $followers,
                'er' => $er,
                'pi' => $piMeta['pi'],
                'prior_n' => $piMeta['prior_n'],
                'early' => $piMeta['early'],
                'hidden_likes' => $this->math->isHiddenLikes($post),
                'pinned' => $this->math->isPinned($post),
                'caption' => $post->caption,
                'hook' => $this->math->hook($post->caption),
                'ctas' => $this->math->detectCtas($post->caption),
                'hashtag_count' => $this->math->hashtagCount($post->caption),
                'mention_count' => $this->math->mentionCount($post->caption),
                'length_bucket' => $this->math->captionLengthBucket($post->caption),
                'theme' => $this->math->classifyTheme($post->caption),
                'hook_pattern' => $this->math->hookPattern($this->math->hook($post->caption)),
                'thumbnail_url' => $post->cover_url,
                'url' => $post->url,
                'dow' => $bucket['dow'] ?? null,
                'block' => $bucket['block'] ?? null,
            ];
        })->values();
    }

    /**
     * @param  Collection<int, Post|array<string, mixed>>  $postsOrRows
     * @return array<string, mixed>
     */
    private function accountPayload(TrackedAccount $account, Collection $postsOrRows): array
    {
        $postsCount = $postsOrRows
            ->filter(function (mixed $row) use ($account): bool {
                if ($row instanceof Post) {
                    return (int) $row->social_account_id === (int) $account->social_account_id;
                }

                return (int) ($row['social_account_id'] ?? 0) === (int) $account->social_account_id;
            })
            ->count();

        $avatar = $account->avatar;
        if (blank($avatar) && filled($account->socialAccount?->avatar)) {
            $avatar = $account->socialAccount->avatar;
        }

        return [
            'id' => $account->id,
            'handle' => $account->handle,
            'display_name' => $account->display_name,
            'avatar' => $avatar,
            'followers' => $account->followers,
            'is_own_account' => (bool) $account->is_own_account,
            'posts_count' => $postsCount,
            'last_synced_at' => $account->last_synced_at?->toIso8601String(),
            'colour' => null,
        ];
    }

    /**
     * @param  Collection<int, TrackedAccount>  $accounts
     * @param  Collection<int, array<string, mixed>>  $enrichedAll
     * @param  Collection<int, array<string, mixed>>  $periodPosts
     * @param  Collection<int, Collection<int, array{captured_on: string, followers: int}>>  $snapshots
     * @return Collection<int, array<string, mixed>>
     */
    private function accountRows(
        Collection $accounts,
        Collection $enrichedAll,
        Collection $periodPosts,
        Collection $snapshots,
        int $periodDays,
    ): Collection {
        return $accounts->map(function (TrackedAccount $account) use ($enrichedAll, $periodPosts, $snapshots, $periodDays): array {
            $sid = (int) $account->social_account_id;
            $all = $enrichedAll->where('social_account_id', $sid)->values();
            $periodAll = $periodPosts->where('social_account_id', $sid)->values();
            // Like-based ER/PI never treat hidden likes as 0 - drop those rows.
            $period = $periodAll
                ->filter(fn (array $row): bool => ! $row['hidden_likes'])
                ->values();

            $ers = $period->pluck('er')->filter(fn ($v) => $v !== null)->values();
            $er = $this->math->median($ers);
            $posts28 = $all->filter(function (array $row) {
                return $row['posted_at'] !== null
                    && $row['posted_at']->gte(CarbonImmutable::now('UTC')->subDays(28));
            })->count();
            $postsPerWeek = $posts28 / 4;

            $snap = $snapshots->get($sid, collect());
            $followersNow = $this->math->followersAt($snap, now(), $account->followers);
            $followersStart = $this->math->followersAt(
                $snap,
                CarbonImmutable::now('UTC')->subDays(min(30, $periodDays)),
                null,
            );
            $growthPct = null;

            if ($followersNow !== null && $followersStart !== null && $followersStart > 0 && $snap->count() >= 2) {
                $growthPct = (($followersNow - $followersStart) / $followersStart) * 100;
            }

            $winners = $period->filter(
                fn (array $row): bool => is_numeric($row['pi']) && (float) $row['pi'] >= DashboardMath::WINNER_THRESHOLD,
            )->count();

            // Format mix uses every imported post; lift/ER still need measurable likes.
            $formatCounts = $periodAll->countBy('format');
            $totalFormat = max(1, $periodAll->count());
            $formatShare = [];

            foreach (['Reel', 'Carousel', 'Image', 'Video'] as $format) {
                $formatShare[$format] = (($formatCounts[$format] ?? 0) / $totalFormat) * 100;
            }

            $formatLift = [];

            foreach (['Reel', 'Carousel', 'Image', 'Video'] as $format) {
                $subset = $period->where('format', $format);
                $subsetEr = $this->math->median($subset->pluck('er')->filter(fn ($v) => $v !== null)->values());

                if ($subset->count() >= 3 && $er !== null && $er > 0 && $subsetEr !== null) {
                    $formatLift[$format] = $subsetEr / $er;
                }
            }

            $topFormat = $periodAll->isEmpty()
                ? null
                : (collect($formatShare)->sortDesc()->keys()->first()
                    ?? collect($formatLift)->sortDesc()->keys()->first());

            $consistency = $this->consistencyDots($all);
            $commentsPerPost = $periodAll->count() > 0
                ? $periodAll->avg(fn (array $row): int => (int) $row['comments'])
                : null;

            $reelReach = $this->math->median(
                $periodAll
                    ->filter(fn (array $row): bool => $row['format'] === 'Reel' && ($row['followers'] ?? 0) > 0 && ($row['views'] ?? 0) > 0)
                    ->map(fn (array $row): float => ((int) $row['views'] / (int) $row['followers']) * 100)
                    ->values(),
            );

            $hiddenInPeriod = $periodAll->filter(fn (array $row): bool => $row['hidden_likes'])->count();

            return [
                'id' => $account->id,
                'social_account_id' => $sid,
                'handle' => $account->handle,
                'display_name' => $account->display_name,
                'avatar' => $account->avatar,
                'is_own_account' => (bool) $account->is_own_account,
                'followers' => $followersNow,
                'growth_pct' => $growthPct,
                'posts_n' => $periodAll->count(),
                'measurable_posts_n' => $period->count(),
                'hidden_likes_n' => $hiddenInPeriod,
                'posts_per_week' => $postsPerWeek,
                'consistency' => $consistency,
                'er' => $er,
                'er_mean' => $ers->isEmpty() ? null : (float) $ers->avg(),
                'comments_per_post' => $commentsPerPost === null ? null : (float) $commentsPerPost,
                'top_format' => $topFormat,
                'winners' => $winners,
                'winner_rate' => $period->count() > 0 ? ($winners / $period->count()) * 100 : null,
                'format_share' => $formatShare,
                'format_lift' => $formatLift,
                'reel_reach' => $reelReach,
                'interactions_sum' => (int) $period->sum(fn (array $row): int => (int) ($row['interactions'] ?? 0)),
            ];
        })->values();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $all
     * @return array{filled: int, weeks: list<bool>}
     */
    private function consistencyDots(Collection $all): array
    {
        $weeks = [];

        for ($i = 7; $i >= 0; $i--) {
            $start = CarbonImmutable::now(DashboardMath::TIMEZONE)->startOfWeek()->subWeeks($i);
            $end = $start->endOfWeek();
            $has = $all->contains(function (array $row) use ($start, $end): bool {
                /** @var CarbonImmutable|null $london */
                $london = $row['london_at'];

                return $london !== null && $london->betweenIncluded($start, $end);
            });
            $weeks[] = $has;
        }

        return [
            'filled' => count(array_filter($weeks)),
            'weeks' => $weeks,
        ];
    }

    /**
     * @param  Collection<int, TrackedAccount>  $rivals
     * @param  Collection<int, array<string, mixed>>  $periodPosts
     * @return array{status: string, n: int, data: mixed, reason: string|null}
     */
    private function onboardingCard(?TrackedAccount $own, Collection $rivals, Collection $periodPosts, int $nonIgCount): array
    {
        $rivalWithEnough = $rivals->contains(function (TrackedAccount $rival) use ($periodPosts): bool {
            return $periodPosts->where('social_account_id', $rival->social_account_id)->count() >= DashboardMath::MIN_SAMPLE;
        });

        if ($rivalWithEnough) {
            return CardResult::empty('Onboarding complete.', 0, ['hide' => true]);
        }

        $steps = [
            [
                'key' => 'own',
                'label' => 'Add your own Instagram',
                'done' => $own !== null,
            ],
            [
                'key' => 'rivals',
                // Never ask to "Add competitors" when Tracking already has Instagram rivals.
                'label' => $rivals->isEmpty()
                    ? 'Add Instagram competitors on Tracking'
                    : sprintf(
                        'Waiting for denser data (%d rival%s tracked - need 5+ posts in period)',
                        $rivals->count(),
                        $rivals->count() === 1 ? '' : 's',
                    ),
                'done' => $rivals->isNotEmpty(),
                'suggestions' => $rivals->isEmpty() ? $this->suggestedHandles() : [],
            ],
            [
                'key' => 'refresh',
                'label' => $periodPosts->isEmpty()
                    ? 'First refresh running - data after the next sync'
                    : sprintf('Need 5+ posts in period from at least one rival (%d so far)', $periodPosts->count()),
                'done' => $periodPosts->count() >= DashboardMath::MIN_SAMPLE,
            ],
        ];

        $note = $nonIgCount > 0 && $rivals->isEmpty()
            ? "Tracking lists {$nonIgCount} non-Instagram account".($nonIgCount === 1 ? '' : 's').'. This dashboard is Instagram-only - add Instagram handles on Tracking to unlock it.'
            : null;

        return CardResult::ok([
            'hide' => false,
            'steps' => $steps,
            'note' => $note,
        ], $rivals->count());
    }

    /**
     * @return list<string>
     */
    private function suggestedHandles(): array
    {
        return [
            'great.friendship',
            'fuss.london',
            'onehousesocialclub',
            'london.theofflineclub',
            'goodgym',
        ];
    }

    /**
     * @param  array<string, mixed>|null  $ownRow
     * @param  Collection<int, array<string, mixed>>  $peerRows
     * @param  Collection<int, array<string, mixed>>  $periodPosts
     * @param  Collection<int, array<string, mixed>>  $enrichedAll
     * @return array<string, mixed>
     */
    private function insightContext(?array $ownRow, Collection $peerRows, Collection $periodPosts, Collection $enrichedAll): array
    {
        $topWinner = $periodPosts
            ->filter(fn (array $row): bool => ! $row['is_own_account'] && is_numeric($row['pi']))
            ->sortByDesc('pi')
            ->first();

        $yourWin = $periodPosts
            ->filter(fn (array $row): bool => $row['is_own_account'] && is_numeric($row['pi']))
            ->sortByDesc('pi')
            ->first();

        $peerFormatLift = [];

        foreach (['Reel', 'Carousel', 'Image'] as $format) {
            $lifts = $peerRows
                ->map(fn (array $row) => $row['format_lift'][$format] ?? null)
                ->filter(fn ($v) => is_numeric($v))
                ->values();
            $n = $periodPosts->where('format', $format)->where('is_own_account', false)->count();

            if ($lifts->isNotEmpty()) {
                $peerFormatLift[$format] = [
                    'lift' => $this->math->median($lifts),
                    'n' => $n,
                ];
            }
        }

        $bestCell = null;
        $cells = [];

        foreach ($periodPosts->where('is_own_account', false) as $row) {
            if ($row['dow'] === null || $row['block'] === null || ! is_numeric($row['pi'])) {
                continue;
            }

            $key = $row['dow'].'-'.$row['block'];
            $cells[$key]['pis'][] = (float) $row['pi'];
            $cells[$key]['dow'] = $row['dow'];
            $cells[$key]['block'] = $row['block'];
        }

        foreach ($cells as $cell) {
            $n = count($cell['pis']);
            $pi = $this->math->median($cell['pis']);

            if ($pi === null) {
                continue;
            }

            if ($bestCell === null || $pi > $bestCell['pi']) {
                $bestCell = [
                    'pi' => $pi,
                    'n' => $n,
                    'day' => DashboardMath::DAYS[$cell['dow']],
                    'block' => DashboardMath::HOUR_BLOCKS[$cell['block']]['label'],
                ];
            }
        }

        $withQ = $periodPosts->filter(fn (array $row): bool => in_array('question', $row['ctas'] ?? [], true));
        $withoutQ = $periodPosts->filter(fn (array $row): bool => ! in_array('question', $row['ctas'] ?? [], true));
        $ctaQuestion = null;

        if ($withQ->count() >= 3 && $withoutQ->count() >= 3) {
            $withComments = (float) $withQ->avg(fn (array $row): int => (int) $row['comments']);
            $withoutComments = (float) $withoutQ->avg(fn (array $row): int => (int) $row['comments']);

            if ($withoutComments > 0) {
                $ctaQuestion = [
                    'comment_ratio' => $withComments / $withoutComments,
                    'n' => $withQ->count() + $withoutQ->count(),
                ];
            }
        }

        $lowHash = $periodPosts->filter(fn (array $row): bool => ($row['hashtag_count'] ?? 0) <= 3 && is_numeric($row['pi']));
        $highHash = $periodPosts->filter(fn (array $row): bool => ($row['hashtag_count'] ?? 0) >= 4 && is_numeric($row['pi']));
        $hashtagLift = null;

        if ($lowHash->count() >= 3 && $highHash->count() >= 3) {
            $lowPi = $this->math->median($lowHash->pluck('pi'));
            $highPi = $this->math->median($highHash->pluck('pi'));

            if ($lowPi !== null && $highPi !== null && $highPi > 0) {
                $hashtagLift = [
                    'ratio' => $lowPi / $highPi,
                    'n' => $lowHash->count() + $highHash->count(),
                ];
            }
        }

        return [
            'own' => $ownRow,
            'rival_rows' => $peerRows->all(),
            'rival_posts_n' => $periodPosts->where('is_own_account', false)->count(),
            'peer_posts_per_week' => $this->math->median($peerRows->pluck('posts_per_week')),
            'peer_er' => $this->math->median($peerRows->pluck('er')->filter(fn ($v) => $v !== null)->values()),
            'peer_growth_pct' => $this->math->median($peerRows->pluck('growth_pct')->filter(fn ($v) => $v !== null)->values()),
            'peer_format_lift' => $peerFormatLift,
            'top_winner' => $topWinner === null ? null : [
                'id' => $topWinner['id'] ?? null,
                'handle' => $topWinner['handle'],
                'pi' => $topWinner['pi'],
                'prior_n' => $topWinner['prior_n'],
                'format' => $topWinner['format'],
                'hook' => $topWinner['hook'],
                'when' => $topWinner['london_at']?->format('l \\a\\t H:i'),
            ],
            'your_win' => $yourWin === null ? null : [
                'id' => $yourWin['id'] ?? null,
                'pi' => $yourWin['pi'],
                'prior_n' => $yourWin['prior_n'],
                'format' => $yourWin['format'],
                'hook' => $yourWin['hook'],
            ],
            'best_heatmap_cell' => $bestCell,
            'cta_question' => $ctaQuestion,
            'hashtag_lift' => $hashtagLift,
            'theme_gap' => $this->themeGapForInsights($periodPosts, $ownRow),
            'wow_change' => null,
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $periodPosts
     * @param  array<string, mixed>|null  $ownRow
     * @return array{theme: string, pi: float, n: int}|null
     */
    private function themeGapForInsights(Collection $periodPosts, ?array $ownRow): ?array
    {
        $peer = $periodPosts->where('is_own_account', false);
        $ownThemes = $periodPosts
            ->where('is_own_account', true)
            ->pluck('theme')
            ->unique()
            ->all();

        $best = null;

        foreach ($peer->groupBy('theme') as $theme => $rows) {
            if ($theme === 'other' || in_array($theme, $ownThemes, true)) {
                continue;
            }

            $pis = $rows->pluck('pi')->filter(fn ($v) => is_numeric($v))->values();

            if ($pis->count() < DashboardMath::MIN_SAMPLE) {
                continue;
            }

            $pi = $this->math->median($pis);

            if ($pi === null || $pi < 1.3) {
                continue;
            }

            if ($best === null || $pi > $best['pi']) {
                $best = [
                    'theme' => $this->math->themeLabel((string) $theme),
                    'pi' => $pi,
                    'n' => $pis->count(),
                ];
            }
        }

        return $best;
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  Collection<int, array<string, mixed>>  $peerRows
     * @param  Collection<int, array<string, mixed>>  $periodPosts
     * @return array{status: string, n: int, data: mixed, reason: string|null}
     */
    private function insightsCard(array $context, Collection $peerRows, Collection $periodPosts): array
    {
        $accountsWithPosts = $periodPosts->pluck('social_account_id')->unique()->count();
        $n = $periodPosts->count();

        if ($accountsWithPosts < 2 || $n < DashboardMath::MIN_SAMPLE) {
            return CardResult::empty(
                'We\'ll write your first insights after the first refresh (need at least 5 posts from 2 accounts).',
                $n,
            );
        }

        $items = $this->insightRules->top($context);

        if ($items === []) {
            return CardResult::insufficient(
                'Not enough signal yet for plain-English insights (need clearer gaps between you and peers).',
                $n,
                ['items' => []],
            );
        }

        return CardResult::ok(['items' => $items], $n);
    }

    /**
     * @param  array<string, mixed>|null  $ownRow
     * @param  Collection<int, array<string, mixed>>  $peerRows
     * @param  Collection<int, array<string, mixed>>  $enrichedAll
     * @return array{status: string, n: int, data: mixed, reason: string|null}
     */
    private function kpisCard(?array $ownRow, Collection $peerRows, Collection $enrichedAll, int $periodDays): array
    {
        if ($ownRow === null && $peerRows->isEmpty()) {
            return CardResult::empty('Add Instagram accounts to see KPIs.');
        }

        $cards = [
            $this->kpiStat(
                key: 'followers_growth',
                label: 'Followers · 30d',
                why: 'Followers · 30d growth: Are you growing as fast as similar clubs?',
                formula: 'F_now; (F_now − F_30d) / F_30d × 100. Real snapshots only.',
                you: $ownRow,
                peers: $peerRows,
                valueKey: 'growth_pct',
                displayKey: 'followers',
                unit: 'pct',
                emptyGrowth: true,
            ),
            $this->kpiStat(
                key: 'posts_per_week',
                label: 'Posts / week',
                why: 'Posts per week: Effort vs peers; the simplest lever.',
                formula: 'posts in last 28 days ÷ 4',
                you: $ownRow,
                peers: $peerRows,
                valueKey: 'posts_per_week',
                unit: 'number',
            ),
            $this->kpiStat(
                key: 'er',
                label: 'ER (per follower)',
                why: 'Engagement rate (per follower): Does your content land with your own audience?',
                formula: 'median((likes+comments)/followers×100) over posts in period',
                you: $ownRow,
                peers: $peerRows,
                valueKey: 'er',
                unit: 'pct',
                requireN: true,
            ),
            $this->kpiStat(
                key: 'winner_rate',
                label: 'Winner rate',
                why: 'Winner rate: How often an account "hits". Size-independent.',
                formula: '% of posts with Performance Index ≥ 2.0',
                you: $ownRow,
                peers: $peerRows,
                valueKey: 'winner_rate',
                unit: 'pct',
                requireN: true,
            ),
            $this->kpiStat(
                key: 'reel_reach',
                label: 'Reel reach',
                why: 'Reel reach proxy: Do Reels travel beyond followers? The only public reach proxy.',
                formula: 'median(reel plays / followers × 100) over Reels in period',
                you: $ownRow,
                peers: $peerRows,
                valueKey: 'reel_reach',
                unit: 'pct',
                reelEmpty: true,
            ),
        ];

        return CardResult::ok([
            'cards' => $cards,
            'period_days' => $periodDays,
        ], (int) ($ownRow['posts_n'] ?? 0));
    }

    /**
     * @param  array<string, mixed>|null  $you
     * @param  Collection<int, array<string, mixed>>  $peers
     * @return array<string, mixed>
     */
    private function kpiStat(
        string $key,
        string $label,
        string $why,
        string $formula,
        ?array $you,
        Collection $peers,
        string $valueKey,
        string $unit,
        string $displayKey = '',
        bool $emptyGrowth = false,
        bool $requireN = false,
        bool $reelEmpty = false,
    ): array {
        $youN = (int) ($you['posts_n'] ?? 0);
        $measurableN = (int) ($you['measurable_posts_n'] ?? $youN);
        $sampleN = $requireN ? $measurableN : $youN;
        $youValue = $you[$valueKey] ?? null;
        $display = $displayKey !== '' ? ($you[$displayKey] ?? null) : $youValue;
        $peerMedian = $this->math->median(
            $peers
                ->filter(function (array $peer) use ($requireN): bool {
                    if (! $requireN) {
                        return true;
                    }

                    return (int) ($peer['measurable_posts_n'] ?? $peer['posts_n'] ?? 0) >= DashboardMath::MIN_SAMPLE;
                })
                ->pluck($valueKey)
                ->filter(fn ($v) => $v !== null)
                ->values(),
        );

        $status = 'ok';
        $reason = null;

        if ($emptyGrowth && $you !== null && $youValue === null) {
            // Still show the current follower count; growth stays "—" until
            // two snapshots exist (do not replace the cell with a paragraph).
            $status = 'ok';
            $reason = 'Growth appears after 2 weekly snapshots.';
        } elseif ($reelEmpty && $you !== null && $youValue === null && $youN > 0) {
            $status = 'ok';
            $reason = 'No Reels in this period.';
            $display = null;
        } elseif ($key === 'posts_per_week' && $you !== null && $youN === 0) {
            $status = 'insufficient';
            $reason = 'No posts imported yet';
            $youValue = null;
            $display = null;
        } elseif ($requireN && $you !== null && $sampleN < DashboardMath::MIN_SAMPLE) {
            $status = 'insufficient';
            $hiddenN = (int) ($you['hidden_likes_n'] ?? 0);
            $reason = $sampleN === 0 && $hiddenN > 0
                ? 'Likes hidden on Instagram'
                : $this->math->insufficientReason($sampleN);
            $youValue = null;
            $display = $displayKey !== '' ? $display : null;
        } elseif ($you === null) {
            $status = 'insufficient';
            $reason = 'Add your own Instagram to compare against peers.';
        }

        $gap = null;

        if (is_numeric($youValue) && is_numeric($peerMedian) && (float) $peerMedian != 0.0) {
            if ($unit === 'pct' && in_array($key, ['er', 'winner_rate', 'followers_growth', 'reel_reach'], true)) {
                $gap = [
                    'type' => 'pp',
                    'value' => $this->math->round1((float) $youValue - (float) $peerMedian),
                ];
            } else {
                $ratio = (float) $youValue / (float) $peerMedian;
                $gap = [
                    'type' => 'x',
                    'value' => $this->math->round1($ratio),
                    'lower' => $ratio < 1,
                ];
            }
        }

        return [
            'key' => $key,
            'label' => $label,
            'why' => $why,
            'formula' => $formula,
            'status' => $status,
            'reason' => $reason,
            'you' => $youValue === null ? null : $this->math->round2((float) $youValue),
            'you_display' => $display === null ? null : (is_numeric($display) ? (int) round((float) $display) : $display),
            'peer_median' => $peerMedian === null ? null : $this->math->round2($peerMedian),
            'gap' => $gap,
            'unit' => $unit,
            'n' => $sampleN,
            'sparkline' => [],
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $accountRows
     * @param  Collection<int, array<string, mixed>>  $periodPosts
     * @return array{status: string, n: int, data: mixed, reason: string|null}
     */
    private function leaderboardCard(Collection $accountRows, Collection $periodPosts): array
    {
        if ($accountRows->isEmpty()) {
            return CardResult::empty('Add Instagram competitors to compare.');
        }

        $totalInteractions = max(1, (int) $accountRows->sum('interactions_sum'));

        $rows = $accountRows
            ->sortByDesc(fn (array $row): int => $row['is_own_account'] ? PHP_INT_MAX : (int) ($row['followers'] ?? 0))
            ->values()
            ->map(function (array $row) use ($totalInteractions): array {
                $n = (int) $row['posts_n'];
                $measurable = (int) ($row['measurable_posts_n'] ?? $n);
                $hiddenN = (int) ($row['hidden_likes_n'] ?? 0);
                $noPosts = $n === 0;
                $erUnavailable = $measurable < DashboardMath::MIN_SAMPLE;

                $rowNote = null;

                if ($noPosts) {
                    $rowNote = 'No posts imported yet';
                } elseif ($measurable === 0 && $hiddenN > 0) {
                    $rowNote = 'Likes hidden on Instagram';
                } elseif ($erUnavailable && $hiddenN > 0) {
                    $rowNote = "Only {$measurable} posts with visible likes";
                }

                return [
                    'handle' => $row['handle'],
                    'display_name' => $row['display_name'],
                    'avatar' => $row['avatar'],
                    'is_own_account' => $row['is_own_account'],
                    'followers' => $row['followers'],
                    'growth_pct' => $row['growth_pct'] === null ? null : $this->math->round1((float) $row['growth_pct']),
                    'posts_per_week' => $noPosts ? null : $this->math->round1((float) $row['posts_per_week']),
                    'consistency' => $row['consistency'],
                    // Treat unavailable / all-zero measurable samples as null, never 0.00%.
                    'er' => ($erUnavailable || $row['er'] === null || ($measurable === 0 && (float) ($row['er'] ?? 0) === 0.0))
                        ? null
                        : $this->math->round2($row['er']),
                    'er_reason' => $erUnavailable || $measurable === 0
                        ? ($measurable === 0 && $hiddenN > 0
                            ? 'Likes hidden on Instagram'
                            : ($measurable === 0
                                ? 'No measurable engagement yet'
                                : $this->math->insufficientReason($measurable)))
                        : null,
                    'comments_per_post' => $noPosts ? null : $this->math->round1($row['comments_per_post']),
                    'top_format' => ($noPosts || $measurable === 0) ? null : $row['top_format'],
                    'winners' => $row['winners'],
                    'engagement_share' => $measurable === 0
                        ? null
                        : $this->math->round1(($row['interactions_sum'] / $totalInteractions) * 100),
                    'posts_n' => $n,
                    'no_posts_in_period' => $noPosts,
                    'row_note' => $rowNote,
                ];
            })
            ->all();

        // Pin own account first.
        usort($rows, function (array $a, array $b): int {
            if ($a['is_own_account'] !== $b['is_own_account']) {
                return $a['is_own_account'] ? -1 : 1;
            }

            return ($b['followers'] ?? -1) <=> ($a['followers'] ?? -1);
        });

        return CardResult::ok(['rows' => $rows], $periodPosts->count());
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $periodPosts
     * @return array{status: string, n: int, data: mixed, reason: string|null}
     */
    private function winnersCard(Collection $periodPosts, bool $showHiddenLikes = false): array
    {
        $visible = $periodPosts
            ->filter(fn (array $row): bool => ! $row['hidden_likes'] && is_numeric($row['pi']))
            ->values();

        $hiddenExtras = collect();

        if ($showHiddenLikes) {
            $hiddenExtras = $periodPosts
                ->filter(fn (array $row): bool => $row['hidden_likes'])
                ->map(function (array $row) use ($periodPosts): array {
                    $sid = (int) $row['social_account_id'];
                    $scoreFn = static fn (array $prior): float => (float) $prior['comments'] + ((float) $prior['views'] * 0.01);

                    // Rank on comments (+ views) vs the account's usual - prefer
                    // visible-like posts as the baseline; if every post hides
                    // likes, fall back to that account's own comment/view median.
                    $proxyBase = $this->math->median(
                        $periodPosts
                            ->where('social_account_id', $sid)
                            ->filter(fn (array $prior): bool => ! $prior['hidden_likes'])
                            ->map($scoreFn)
                            ->values(),
                    );

                    if ($proxyBase === null || $proxyBase <= 0) {
                        $proxyBase = $this->math->median(
                            $periodPosts
                                ->where('social_account_id', $sid)
                                ->filter(fn (array $prior): bool => $prior['hidden_likes'])
                                ->map($scoreFn)
                                ->values(),
                        );
                    }

                    $score = $scoreFn($row);
                    $row['pi'] = $proxyBase !== null && $proxyBase > 0
                        ? $score / $proxyBase
                        : null;
                    $row['likes_hidden_badge'] = true;

                    return $row;
                })
                ->filter(fn (array $row): bool => is_numeric($row['pi']))
                ->values();
        }

        $scored = $visible->concat($hiddenExtras)->values();

        if ($scored->isEmpty()) {
            $early = $periodPosts->filter(fn (array $row): bool => ($row['prior_n'] ?? 0) < DashboardMath::PI_MIN_PRIORS);
            $example = $early->sortBy('prior_n')->first();

            if ($example !== null) {
                return CardResult::insufficient(
                    sprintf(
                        'We need ~10 posts per account to spot winners. @%s has %d.',
                        $example['handle'] ?? 'account',
                        (int) $example['prior_n'],
                    ),
                    $periodPosts->count(),
                );
            }

            return CardResult::empty('No standout posts this period. Everyone posted close to their usual.', $periodPosts->count());
        }

        $winners = $scored
            ->filter(fn (array $row): bool => (float) $row['pi'] >= DashboardMath::WINNER_THRESHOLD)
            ->sortByDesc('pi')
            ->take(12)
            ->map(fn (array $row): array => $this->winnerPayload($row))
            ->values()
            ->all();

        $flops = $scored
            ->filter(fn (array $row): bool => (float) $row['pi'] <= DashboardMath::FLOP_THRESHOLD && ! ($row['likes_hidden_badge'] ?? false))
            ->sortBy('pi')
            ->take(6)
            ->map(fn (array $row): array => $this->winnerPayload($row))
            ->values()
            ->all();

        $hiddenCount = $periodPosts->filter(fn (array $row): bool => $row['hidden_likes'])->count();
        $hiddenSpotlight = $showHiddenLikes
            ? $hiddenExtras
                ->sortByDesc('pi')
                ->take(3)
                ->map(fn (array $row): array => $this->winnerPayload($row))
                ->values()
                ->all()
            : [];

        $meta = [
            'hidden_likes_count' => $hiddenCount,
            'hidden_included' => $showHiddenLikes ? $hiddenExtras->count() : 0,
            'hidden_spotlight' => $hiddenSpotlight,
        ];

        if ($winners === []) {
            return CardResult::empty(
                'No standout posts this period. Everyone posted close to their usual.',
                $scored->count(),
                ['winners' => [], 'flops' => $flops, ...$meta],
            );
        }

        return CardResult::ok([
            'winners' => $winners,
            'flops' => $flops,
            ...$meta,
        ], count($winners));
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function winnerPayload(array $row): array
    {
        return [
            'id' => $row['id'],
            'handle' => $row['handle'],
            'tracked_account_id' => $row['tracked_account_id'] ?? null,
            'is_own_account' => $row['is_own_account'],
            'format' => $row['format'],
            'posted_at' => $row['london_at']?->format('D j M, H:i'),
            'pi' => $this->math->round1((float) $row['pi']),
            'early' => (bool) $row['early'],
            'likes' => $row['likes'],
            'likes_hidden' => (bool) ($row['likes_hidden_badge'] ?? $row['hidden_likes'] ?? false),
            'comments' => $row['comments'],
            'views' => $row['format'] === 'Reel' ? $row['views'] : null,
            'hook' => $row['hook'],
            'tags' => array_values(array_filter([
                ($row['mention_count'] ?? 0) > 0 ? 'collab' : null,
                in_array('question', $row['ctas'] ?? [], true) ? 'question' : null,
                in_array('link_in_bio', $row['ctas'] ?? [], true) ? 'link in bio' : null,
                ($row['likes_hidden_badge'] ?? false) ? 'likes hidden' : null,
            ])),
            'thumbnail_url' => $row['thumbnail_url'],
            'url' => $row['url'],
        ];
    }

    /**
     * @param  Collection<int, TrackedAccount>  $accounts
     * @param  Collection<int, Collection<int, array{captured_on: string, followers: int}>>  $snapshots
     * @return array{status: string, n: int, data: mixed, reason: string|null}
     */
    private function growthSeriesCard(Collection $accounts, Collection $snapshots): array
    {
        $series = [];
        $pointCount = 0;

        foreach ($accounts as $account) {
            $rows = $snapshots->get($account->social_account_id, collect())
                ->sortBy('captured_on')
                ->values();

            if ($rows->isEmpty()) {
                continue;
            }

            $start = (int) $rows->first()['followers'];
            $points = $rows->map(function (array $row) use ($start): array {
                $followers = (int) $row['followers'];
                $pct = $start > 0 ? (($followers / $start) - 1) * 100 : null;

                return [
                    'date' => $row['captured_on'],
                    'followers' => $followers,
                    'pct_change' => $pct === null ? null : round($pct, 2),
                ];
            })->all();

            $pointCount += count($points);
            $series[] = [
                'handle' => $account->handle,
                'is_own_account' => (bool) $account->is_own_account,
                'points' => $points,
            ];
        }

        if ($series === []) {
            return CardResult::empty(
                'Growth history starts when tracking begins. Check back after the next weekly refresh.',
            );
        }

        $maxPoints = collect($series)->max(fn (array $row): int => count($row['points']));

        if ($maxPoints < 2) {
            return CardResult::insufficient(
                'Tracking started recently. Growth appears after 2 weekly snapshots.',
                $pointCount,
                ['series' => $series, 'mode' => 'pct'],
            );
        }

        return CardResult::ok(['series' => $series, 'mode' => 'pct'], $pointCount);
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $accountRows
     * @return array{status: string, n: int, data: mixed, reason: string|null}
     */
    private function efficiencyCard(Collection $accountRows): array
    {
        $usable = $accountRows
            ->filter(fn (array $row): bool => ($row['measurable_posts_n'] ?? $row['posts_n'] ?? 0) >= DashboardMath::MIN_SAMPLE && $row['er'] !== null)
            ->values();

        if ($usable->count() < 2) {
            return CardResult::insufficient(
                'Add another competitor to compare posting strategies (need 2+ accounts with n≥5).',
                $usable->count(),
            );
        }

        $points = $usable->map(fn (array $row): array => [
            'handle' => $row['handle'],
            'is_own_account' => $row['is_own_account'],
            'x' => $this->math->round2((float) $row['posts_per_week']),
            'y' => $this->math->round2((float) $row['er']),
            'followers' => $row['followers'],
            'n' => $row['posts_n'],
        ])->all();

        $peer = $usable->where('is_own_account', false);

        return CardResult::ok([
            'points' => $points,
            'median_x' => $this->math->round2($this->math->median($peer->pluck('posts_per_week'))),
            'median_y' => $this->math->round2($this->math->median($peer->pluck('er')->filter(fn ($v) => $v !== null)->values())),
        ], $usable->count());
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $accountRows
     * @param  Collection<int, array<string, mixed>>  $periodPosts
     * @return array{status: string, n: int, data: mixed, reason: string|null}
     */
    private function formatMixCard(Collection $accountRows, Collection $periodPosts): array
    {
        $rows = $accountRows
            ->filter(fn (array $row): bool => ($row['posts_n'] ?? 0) > 0)
            ->map(fn (array $row): array => [
                'handle' => $row['handle'],
                'is_own_account' => $row['is_own_account'],
                'n' => $row['posts_n'],
                'shares' => [
                    'Reel' => $this->math->round1((float) ($row['format_share']['Reel'] ?? 0)),
                    'Carousel' => $this->math->round1((float) ($row['format_share']['Carousel'] ?? 0)),
                    'Image' => $this->math->round1((float) ($row['format_share']['Image'] ?? 0)),
                    'Video' => $this->math->round1((float) ($row['format_share']['Video'] ?? 0)),
                ],
            ])
            ->values();

        if ($rows->isEmpty()) {
            return CardResult::empty('No posts in this period to show format mix.', $periodPosts->count());
        }

        $formatsSeen = $rows->flatMap(fn (array $row) => collect($row['shares'])->filter(fn ($v) => $v > 0)->keys())->unique()->count();

        if ($formatsSeen < 2) {
            return CardResult::insufficient('Only 1 format seen so far.', $periodPosts->count(), ['rows' => $rows->all()]);
        }

        return CardResult::ok(['rows' => $rows->all()], $periodPosts->count());
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $accountRows
     * @param  Collection<int, array<string, mixed>>  $periodPosts
     * @return array{status: string, n: int, data: mixed, reason: string|null}
     */
    private function formatLiftCard(Collection $accountRows, Collection $periodPosts): array
    {
        $formats = ['Reel', 'Carousel', 'Image', 'Video'];
        $rows = [];

        foreach ($accountRows as $account) {
            $lifts = [];

            foreach ($formats as $format) {
                if (! isset($account['format_lift'][$format])) {
                    continue;
                }

                $n = $periodPosts
                    ->filter(fn (array $row): bool => $row['handle'] === $account['handle'] && $row['format'] === $format)
                    ->count();

                if ($n < 3) {
                    continue;
                }

                $lifts[$format] = [
                    'lift' => $this->math->round2((float) $account['format_lift'][$format]),
                    'n' => $n,
                ];
            }

            if ($lifts === []) {
                continue;
            }

            $rows[] = [
                'handle' => $account['handle'],
                'is_own_account' => $account['is_own_account'],
                'lifts' => $lifts,
            ];
        }

        if ($rows === []) {
            return CardResult::insufficient(
                'Not enough posts per format (need 3).',
                $periodPosts->count(),
            );
        }

        $peerLift = [];

        foreach ($formats as $format) {
            $values = collect($rows)
                ->where('is_own_account', false)
                ->map(fn (array $row) => $row['lifts'][$format]['lift'] ?? null)
                ->filter(fn ($v) => $v !== null)
                ->values();

            if ($values->isNotEmpty()) {
                $peerLift[$format] = $this->math->round2($this->math->median($values));
            }
        }

        return CardResult::ok([
            'rows' => $rows,
            'peer_median_lift' => $peerLift,
        ], $periodPosts->count());
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $periodPosts
     * @return array{status: string, n: int, data: mixed, reason: string|null}
     */
    private function heatmapCard(Collection $periodPosts): array
    {
        $rivalPosts = $periodPosts
            ->filter(fn (array $row): bool => ! $row['is_own_account'] && $row['dow'] !== null && $row['block'] !== null)
            ->values();

        $cells = [];

        for ($dow = 0; $dow < 7; $dow++) {
            for ($block = 0; $block < 6; $block++) {
                $cells[$dow][$block] = ['pi' => null, 'n' => 0, 'count' => 0];
            }
        }

        $piBuckets = [];

        foreach ($rivalPosts as $row) {
            $dow = (int) $row['dow'];
            $block = (int) $row['block'];
            $cells[$dow][$block]['count']++;

            if (is_numeric($row['pi'])) {
                $piBuckets[$dow][$block][] = (float) $row['pi'];
            }
        }

        foreach ($piBuckets as $dow => $blocks) {
            foreach ($blocks as $block => $values) {
                $cells[$dow][$block]['n'] = count($values);
                $cells[$dow][$block]['pi'] = count($values) >= 3
                    ? $this->math->round2($this->math->median($values))
                    : null;
            }
        }

        $ownDots = $periodPosts
            ->filter(fn (array $row): bool => $row['is_own_account'] && $row['dow'] !== null && $row['block'] !== null)
            ->map(fn (array $row): array => [
                'dow' => $row['dow'],
                'block' => $row['block'],
            ])
            ->values()
            ->all();

        $n = $rivalPosts->count();

        if ($n < 14) {
            return CardResult::insufficient(
                "We need ~30 posts across your competitors to find reliable time slots (have {$n}).",
                $n,
                [
                    'mode' => 'count',
                    'days' => DashboardMath::DAYS,
                    'blocks' => array_column(DashboardMath::HOUR_BLOCKS, 'label'),
                    'cells' => $cells,
                    'own_dots' => $ownDots,
                ],
            );
        }

        return CardResult::ok([
            'mode' => 'pi',
            'days' => DashboardMath::DAYS,
            'blocks' => array_column(DashboardMath::HOUR_BLOCKS, 'label'),
            'cells' => $cells,
            'own_dots' => $ownDots,
        ], $n);
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $periodPosts
     * @param  Collection<int, array<string, mixed>>  $accountRows
     * @return array{status: string, n: int, data: mixed, reason: string|null}
     */
    private function captionsCard(Collection $periodPosts, Collection $accountRows): array
    {
        $scored = $periodPosts->filter(fn (array $row): bool => is_numeric($row['pi']) && ! $row['hidden_likes']);

        if ($scored->count() < DashboardMath::MIN_SAMPLE) {
            return CardResult::insufficient(
                $this->math->insufficientReason($scored->count()),
                $scored->count(),
            );
        }

        $lengthBuckets = [];

        foreach (['<50', '50-150', '150-500', '500+'] as $bucket) {
            $rows = $scored->where('length_bucket', $bucket);
            $n = $rows->count();
            $lengthBuckets[] = [
                'bucket' => $bucket,
                'n' => $n,
                'pi' => $n >= 3 ? $this->math->round2($this->math->median($rows->pluck('pi'))) : null,
            ];
        }

        $ctaTypes = ['question', 'tag_friend', 'comment', 'save', 'share', 'link_in_bio', 'dm_or_signup'];
        $ctaRows = [];

        foreach ($ctaTypes as $type) {
            $with = $scored->filter(fn (array $row): bool => in_array($type, $row['ctas'] ?? [], true));
            $without = $scored->filter(fn (array $row): bool => ! in_array($type, $row['ctas'] ?? [], true));
            $nWith = $with->count();

            if ($nWith < 3) {
                continue;
            }

            $ctaRows[] = [
                'type' => str_replace('_', ' ', $type),
                'share_pct' => $this->math->round1(($nWith / max(1, $scored->count())) * 100),
                'pi_with' => $this->math->round2($this->math->median($with->pluck('pi'))),
                'pi_without' => $without->count() >= 3
                    ? $this->math->round2($this->math->median($without->pluck('pi')))
                    : null,
                'n' => $nWith,
            ];
        }

        $hashBuckets = [];

        foreach ([['0', fn ($n) => $n === 0], ['1-3', fn ($n) => $n >= 1 && $n <= 3], ['4+', fn ($n) => $n >= 4]] as [$label, $pred]) {
            $rows = $scored->filter(fn (array $row): bool => $pred((int) ($row['hashtag_count'] ?? 0)));
            $n = $rows->count();
            $hashBuckets[] = [
                'bucket' => $label,
                'n' => $n,
                'pi' => $n >= 3 ? $this->math->round2($this->math->median($rows->pluck('pi'))) : null,
            ];
        }

        $hooks = $scored
            ->filter(fn (array $row): bool => (float) $row['pi'] >= DashboardMath::WINNER_THRESHOLD && filled($row['hook']))
            ->sortByDesc('pi')
            ->take(5)
            ->map(fn (array $row): array => [
                'handle' => $row['handle'],
                'hook' => $row['hook'],
                'pattern' => $row['hook_pattern'] ?? 'plain',
                'pi' => $this->math->round1((float) $row['pi']),
            ])
            ->values()
            ->all();

        $avgHashtags = $accountRows->map(fn (array $row): array => [
            'handle' => $row['handle'],
            'is_own_account' => $row['is_own_account'],
            'avg' => $this->math->round1(
                (float) $periodPosts
                    ->where('social_account_id', $row['social_account_id'] ?? null)
                    ->avg('hashtag_count'),
            ),
        ])->all();

        // Fallback: attach by handle when social_account_id missing on accountRows
        if ($avgHashtags === [] || collect($avgHashtags)->every(fn (array $r) => $r['avg'] === null)) {
            $avgHashtags = $accountRows->map(function (array $row) use ($periodPosts): array {
                $posts = $periodPosts->where('handle', $row['handle']);

                return [
                    'handle' => $row['handle'],
                    'is_own_account' => $row['is_own_account'],
                    'avg' => $posts->isEmpty()
                        ? null
                        : $this->math->round1((float) $posts->avg('hashtag_count')),
                ];
            })->all();
        }

        return CardResult::ok([
            'length_buckets' => $lengthBuckets,
            'ctas' => $ctaRows,
            'hashtag_buckets' => $hashBuckets,
            'avg_hashtags' => $avgHashtags,
            'hooks' => $hooks,
        ], $scored->count());
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $periodPosts
     * @param  Collection<int, array<string, mixed>>  $accountRows
     * @return array{status: string, n: int, data: mixed, reason: string|null}
     */
    private function themesCard(Collection $periodPosts, Collection $accountRows): array
    {
        $usable = $periodPosts->filter(fn (array $row): bool => filled($row['theme'] ?? null));

        if ($usable->count() < 10) {
            return CardResult::insufficient(
                'Need 10+ classified posts.',
                $usable->count(),
            );
        }

        $themes = $usable->pluck('theme')->unique()->sort()->values();
        $handles = $accountRows->map(fn (array $row): array => [
            'handle' => $row['handle'],
            'is_own_account' => $row['is_own_account'],
        ])->values();

        $matrix = [];

        foreach ($themes as $theme) {
            $cells = [];

            foreach ($handles as $account) {
                $rows = $usable->where('handle', $account['handle'])->where('theme', $theme);
                $accountPosts = $usable->where('handle', $account['handle']);
                $n = $rows->count();
                $share = $accountPosts->count() > 0
                    ? $this->math->round1(($n / $accountPosts->count()) * 100)
                    : 0.0;
                $pis = $rows->pluck('pi')->filter(fn ($v) => is_numeric($v))->values();
                $cells[] = [
                    'handle' => $account['handle'],
                    'share' => $share,
                    'pi' => $pis->count() >= 3 ? $this->math->round2($this->math->median($pis)) : null,
                    'n' => $n,
                ];
            }

            $matrix[] = [
                'theme' => $this->math->themeLabel((string) $theme),
                'theme_key' => (string) $theme,
                'cells' => $cells,
            ];
        }

        $ownHandle = $accountRows->firstWhere('is_own_account', true)['handle'] ?? null;
        $gaps = [];

        foreach ($matrix as $row) {
            $ownCell = collect($row['cells'])->firstWhere('handle', $ownHandle);
            $peerCells = collect($row['cells'])->where('handle', '!=', $ownHandle);
            $peerPi = $this->math->median($peerCells->pluck('pi')->filter(fn ($v) => $v !== null)->values());
            $ownShare = (float) ($ownCell['share'] ?? 0);

            if ($peerPi !== null && $peerPi >= 1.3 && $ownShare <= 0.0) {
                $gaps[] = [
                    'theme' => $row['theme'],
                    'peer_pi' => $this->math->round1($peerPi),
                    'n' => (int) $peerCells->sum('n'),
                ];
            }
        }

        return CardResult::ok([
            'accounts' => $handles->all(),
            'matrix' => $matrix,
            'gaps' => array_slice($gaps, 0, 5),
        ], $usable->count());
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $accountRows
     * @param  Collection<int, array<string, mixed>>  $enrichedAll
     * @param  Collection<int, Collection<int, array{captured_on: string, followers: int}>>  $snapshots
     * @return array{status: string, n: int, data: mixed, reason: string|null}
     */
    private function weeklyCard(Collection $accountRows, Collection $enrichedAll, Collection $snapshots): array
    {
        $now = CarbonImmutable::now(DashboardMath::TIMEZONE)->startOfWeek();
        $weeks = [];

        for ($i = 11; $i >= 0; $i--) {
            $start = $now->subWeeks($i);
            $weeks[] = [
                'label' => $start->format('j M'),
                'start' => $start->utc(),
                'end' => $start->endOfWeek()->utc(),
            ];
        }

        $series = $accountRows->map(function (array $row) use ($weeks, $enrichedAll, $snapshots): array {
            $posts = $enrichedAll->where('handle', $row['handle']);
            $snap = $snapshots->get($row['social_account_id'] ?? ($posts->first()['social_account_id'] ?? -1), collect());

            $points = [];

            foreach ($weeks as $week) {
                $weekPosts = $posts->filter(function (array $post) use ($week): bool {
                    if ($post['posted_at'] === null) {
                        return false;
                    }

                    $posted = CarbonImmutable::parse($post['posted_at']);

                    return $posted->gte($week['start']) && $posted->lte($week['end']);
                });
                $visible = $weekPosts->filter(fn (array $p): bool => ! $p['hidden_likes']);
                $ers = $visible->pluck('er')->filter(fn ($v) => $v !== null)->values();
                $followers = $this->math->followersAt($snap, $week['end'], $row['followers']);

                $points[] = [
                    'label' => $week['label'],
                    'posts' => $weekPosts->count(),
                    'interactions' => (int) $visible->sum(fn (array $p): int => (int) ($p['interactions'] ?? 0)),
                    'er' => $ers->count() >= 1 ? $this->math->round2($this->math->median($ers)) : null,
                    'followers' => $followers,
                ];
            }

            return [
                'handle' => $row['handle'],
                'is_own_account' => $row['is_own_account'],
                'points' => $points,
            ];
        })->values();

        $own = $series->firstWhere('is_own_account', true);
        $deltas = null;

        if ($own !== null && count($own['points']) >= 2) {
            $thisWeek = $own['points'][count($own['points']) - 1];
            $lastWeek = $own['points'][count($own['points']) - 2];
            $deltas = [
                'posts' => $thisWeek['posts'] - $lastWeek['posts'],
                'er' => ($thisWeek['er'] !== null && $lastWeek['er'] !== null)
                    ? $this->math->round1($thisWeek['er'] - $lastWeek['er'])
                    : null,
            ];
        }

        $n = $enrichedAll->count();

        if ($n < 5) {
            return CardResult::insufficient('Trends appear after 2 weekly refreshes.', $n);
        }

        return CardResult::ok([
            'weeks' => array_column($weeks, 'label'),
            'series' => $series->all(),
            'deltas' => $deltas,
        ], $n);
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $accountRows
     * @return array{status: string, n: int, data: mixed, reason: string|null}
     */
    private function attentionCard(Collection $accountRows): array
    {
        $usable = $accountRows->filter(fn (array $row): bool => ($row['posts_n'] ?? 0) > 0)->values();

        if ($usable->count() < 2) {
            return CardResult::insufficient('Needs 2+ accounts with posts in the period.', $usable->count());
        }

        $totalInteractions = max(1, (float) $usable->sum('interactions_sum'));
        $totalPosts = max(1, (float) $usable->sum('posts_n'));

        $rows = $usable->map(fn (array $row): array => [
            'handle' => $row['handle'],
            'is_own_account' => $row['is_own_account'],
            'eng_share' => $this->math->round1((((float) ($row['interactions_sum'] ?? 0)) / $totalInteractions) * 100),
            'post_share' => $this->math->round1((((float) $row['posts_n']) / $totalPosts) * 100),
        ])->all();

        return CardResult::ok(['rows' => $rows], $usable->count());
    }

    /**
     * @param  array<string, mixed>  $insightContext
     * @param  array<string, mixed>|null  $ownRow
     * @return array{status: string, n: int, data: mixed, reason: string|null}
     */
    private function actionsCard(array $insightContext, ?array $ownRow): array
    {
        if ($ownRow === null) {
            $peerOnly = collect($this->insightRules->top($insightContext, 3))
                ->map(fn (array $row): array => [
                    'text' => strip_tags(str_replace('**', '', $row['text'])),
                    'links_to' => $this->insightRules->resolveAnchor((string) $row['links_to']),
                    'n' => $row['n'],
                ])
                ->all();

            return CardResult::insufficient(
                'Add your own account to get personalised actions. Meanwhile, here is what works for peers:',
                count($peerOnly),
                ['items' => $peerOnly, 'peer_only' => true],
            );
        }

        $items = [];
        $peerPosts = $insightContext['peer_posts_per_week'] ?? null;
        $youPosts = $ownRow['posts_per_week'] ?? null;

        if (is_numeric($peerPosts) && is_numeric($youPosts) && (float) $youPosts < 0.5 * (float) $peerPosts) {
            $items[] = [
                'text' => sprintf('Post %.1f×/week (peers: %.1f)', max(1, round((float) $peerPosts)), (float) $peerPosts),
                'links_to' => $this->insightRules->resolveAnchor('kpis'),
                'n' => (int) ($ownRow['posts_n'] ?? 0),
            ];
        }

        foreach ($this->insightRules->top($insightContext, 8) as $insight) {
            if (count($items) >= 3) {
                break;
            }

            if (in_array($insight['category'], ['frequency', 'your_win'], true)) {
                continue;
            }

            $items[] = [
                'text' => strip_tags(str_replace('**', '', $insight['text'])),
                'links_to' => $this->insightRules->resolveAnchor((string) $insight['links_to']),
                'n' => $insight['n'],
            ];
        }

        $items = array_slice($items, 0, 3);

        if ($items === []) {
            return CardResult::empty('Post 5 times to unlock comparisons.', (int) ($ownRow['posts_n'] ?? 0));
        }

        return CardResult::ok(['items' => $items, 'peer_only' => false], count($items));
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $accountRows
     * @param  Collection<int, array<string, mixed>>  $periodPosts
     * @return array{status: string, n: int, data: mixed, reason: string|null}
     */
    private function dataNotesCard(Collection $accountRows, Collection $periodPosts, int $periodDays, mixed $lastSynced): array
    {
        $hidden = $periodPosts->filter(fn (array $row): bool => $row['hidden_likes'])->count();
        $perAccount = $accountRows->map(fn (array $row): array => [
            'handle' => $row['handle'],
            'is_own_account' => $row['is_own_account'],
            'posts' => (int) ($row['posts_n'] ?? 0),
        ])->all();

        $from = CarbonImmutable::now(DashboardMath::TIMEZONE)->subDays($periodDays)->format('j M Y');
        $to = CarbonImmutable::now(DashboardMath::TIMEZONE)->format('j M Y');

        return CardResult::ok([
            'accounts' => $perAccount,
            'range' => "{$from} - {$to}",
            'last_refreshed_at' => $lastSynced instanceof \DateTimeInterface
                ? CarbonImmutable::parse($lastSynced)->timezone(DashboardMath::TIMEZONE)->toIso8601String()
                : null,
            'excluded_hidden_likes' => $hidden,
            'note' => 'Reach, saves and shares are private to each account and not included.',
        ], $periodPosts->count());
    }

    /**
     * Compact one-row KPI strip: own value + peer median hint where relevant.
     *
     * @param  Collection<int, TrackedAccount>  $visibleAccounts
     * @param  array<string, mixed>|null  $ownRow
     * @param  Collection<int, array<string, mixed>>  $peerRows
     * @param  Collection<int, array<string, mixed>>  $periodPosts
     * @param  array{status: string, n: int, data: mixed, reason: string|null}  $winners
     * @param  array{followers: int, week_delta: int|null, week_pct: float|null}  $growthDelta
     * @param  array<string, mixed>  $captionIntel
     * @return array{cells: list<array<string, mixed>>, ready: bool}
     */
    private function railCard(
        Collection $visibleAccounts,
        ?array $ownRow,
        Collection $peerRows,
        Collection $periodPosts,
        array $winners,
        array $growthDelta,
        array $captionIntel,
        mixed $lastSynced,
        int $periodDays,
    ): array {
        $followers = (int) $visibleAccounts->sum(fn (TrackedAccount $a): int => (int) ($a->followers ?? 0));
        $syncHint = $lastSynced instanceof \DateTimeInterface
            ? 'Last sync '.CarbonImmutable::parse($lastSynced)->timezone(DashboardMath::TIMEZONE)->format('j M')
            : 'No sync yet';

        $measurable = $periodPosts->filter(fn (array $row): bool => ! $row['hidden_likes']);
        $avgViews = $periodPosts->isEmpty()
            ? null
            : round((float) $periodPosts->avg(fn (array $row): int => (int) ($row['views'] ?? 0)), 0);
        $avgLikes = $measurable->isEmpty()
            ? null
            : round((float) $measurable->avg(fn (array $row): int => (int) ($row['likes'] ?? 0)), 0);

        $youEr = $ownRow['er'] ?? null;
        $peerEr = $this->math->median(
            $peerRows
                ->filter(fn (array $row): bool => (int) ($row['measurable_posts_n'] ?? 0) >= DashboardMath::MIN_SAMPLE)
                ->pluck('er')
                ->filter(fn ($v) => $v !== null)
                ->values(),
        );
        $youPpw = $ownRow['posts_per_week'] ?? null;
        $peerPpw = $this->math->median($peerRows->pluck('posts_per_week')->filter(fn ($v) => $v !== null)->values());
        $winnerCount = is_array($winners['data'] ?? null)
            ? count($winners['data']['winners'] ?? [])
            : 0;
        $ctaPosts = (int) ($captionIntel['cta_clicks']['posts_with_cta'] ?? 0);
        $ctaTotal = (int) ($captionIntel['cta_clicks']['posts'] ?? $periodPosts->count());

        $cells = [
            [
                'key' => 'accounts',
                'label' => 'Accounts',
                'value' => (string) $visibleAccounts->count(),
                'hint' => $this->compactNumber($followers).' followers · '.$syncHint,
                'href' => 'tracking',
                'you' => null,
                'peer' => null,
            ],
            [
                'key' => 'posts',
                'label' => 'Posts',
                'value' => (string) $periodPosts->count(),
                'hint' => "Last {$periodDays}d · reels, stills, carousels",
                'href' => 'feed',
                'you' => $ownRow !== null ? (int) ($ownRow['posts_n'] ?? 0) : null,
                'peer' => $peerRows->isEmpty()
                    ? null
                    : (int) round((float) $peerRows->avg(fn (array $row): int => (int) ($row['posts_n'] ?? 0))),
            ],
            [
                'key' => 'winners',
                'label' => 'Winners',
                'value' => (string) $winnerCount,
                'hint' => 'PI ≥ 2.0× this period',
                'href' => 'winners',
                'you' => $ownRow !== null ? (int) ($ownRow['winners'] ?? 0) : null,
                'peer' => $peerRows->isEmpty()
                    ? null
                    : (int) round((float) $peerRows->avg(fn (array $row): int => (int) ($row['winners'] ?? 0))),
            ],
            [
                'key' => 'avg_views',
                'label' => 'Avg views',
                'value' => $avgViews === null ? '—' : $this->compactNumber((int) $avgViews),
                'hint' => $this->compareHint(
                    $ownRow !== null ? (float) ($periodPosts->where('is_own_account', true)->avg(fn (array $r) => (int) ($r['views'] ?? 0)) ?? 0) : null,
                    $peerRows->isEmpty() ? null : (float) $periodPosts->where('is_own_account', false)->avg(fn (array $r) => (int) ($r['views'] ?? 0)),
                    'views',
                ),
                'href' => null,
                'you' => null,
                'peer' => null,
            ],
            [
                'key' => 'avg_likes',
                'label' => 'Avg likes',
                'value' => $avgLikes === null ? '—' : $this->compactNumber((int) $avgLikes),
                'hint' => 'Hidden likes excluded',
                'href' => null,
                'you' => null,
                'peer' => null,
            ],
            [
                'key' => 'er',
                'label' => 'Engagement rate',
                'value' => $youEr !== null
                    ? $this->math->round2((float) $youEr).'%'
                    : ($peerEr !== null ? $this->math->round2((float) $peerEr).'%' : '—'),
                'hint' => $this->compareHint(
                    $youEr !== null ? (float) $youEr : null,
                    $peerEr !== null ? (float) $peerEr : null,
                    'pct',
                    youLabel: 'You',
                    peerLabel: 'peer median',
                ),
                'href' => null,
                'you' => $youEr !== null ? $this->math->round2((float) $youEr) : null,
                'peer' => $peerEr !== null ? $this->math->round2((float) $peerEr) : null,
            ],
            [
                'key' => 'growth',
                'label' => 'Growth',
                'value' => $this->compactNumber((int) ($growthDelta['followers'] ?? 0)),
                'hint' => $growthDelta['week_delta'] !== null
                    ? sprintf(
                        '%s this week%s',
                        $this->signedCompact((int) $growthDelta['week_delta']),
                        $growthDelta['week_pct'] !== null
                            ? ' ('.$this->signedNumber((float) $growthDelta['week_pct']).'%)'
                            : '',
                    )
                    : 'No earlier count yet',
                'href' => null,
                'you' => $ownRow['growth_pct'] ?? null,
                'peer' => $this->math->median($peerRows->pluck('growth_pct')->filter(fn ($v) => $v !== null)->values()),
            ],
            [
                'key' => 'posts_per_week',
                'label' => 'Posts / week',
                'value' => $youPpw !== null
                    ? (string) $this->math->round1((float) $youPpw)
                    : ($peerPpw !== null ? (string) $this->math->round1((float) $peerPpw) : '—'),
                'hint' => $this->compareHint(
                    $youPpw !== null ? (float) $youPpw : null,
                    $peerPpw !== null ? (float) $peerPpw : null,
                    'number',
                    youLabel: 'You',
                    peerLabel: 'peer median',
                ),
                'href' => null,
                'you' => $youPpw !== null ? $this->math->round1((float) $youPpw) : null,
                'peer' => $peerPpw !== null ? $this->math->round1((float) $peerPpw) : null,
            ],
            [
                'key' => 'cta',
                'label' => 'Posts with an ask',
                'value' => (string) $ctaPosts,
                'hint' => $ctaTotal > 0
                    ? sprintf('%d of %d analysed (%d%%)', $ctaPosts, $ctaTotal, (int) round(($ctaPosts / $ctaTotal) * 100))
                    : 'In analysed captions',
                'href' => null,
                'you' => null,
                'peer' => null,
            ],
        ];

        return [
            'cells' => $cells,
            'ready' => $visibleAccounts->isNotEmpty(),
        ];
    }

    /**
     * @param  Collection<int, TrackedAccount>  $accounts
     * @param  Collection<int, Collection<int, array{captured_on: string, followers: int}>>  $snapshots
     * @return array{followers: int, week_delta: int|null, week_pct: float|null}
     */
    private function growthDeltaFromSnapshots(Collection $accounts, Collection $snapshots): array
    {
        $today = CarbonImmutable::now()->toDateString();
        $weekAgo = CarbonImmutable::now()->subDays(7)->toDateString();
        $current = 0;
        $weekNow = 0;
        $weekThen = 0;
        $matched = false;

        foreach ($accounts as $account) {
            $sid = (int) $account->social_account_id;
            $rows = $snapshots->get($sid, collect())->sortBy('captured_on')->values();
            $now = $this->math->followersAt($rows, CarbonImmutable::parse($today), $account->followers);
            $then = $this->math->followersAt($rows, CarbonImmutable::parse($weekAgo), null);

            if ($now !== null) {
                $current += $now;
            }

            if ($now !== null && $then !== null) {
                $matched = true;
                $weekNow += $now;
                $weekThen += $then;
            }
        }

        $delta = $matched ? $weekNow - $weekThen : null;
        $pct = $matched && $weekThen > 0
            ? round((($weekNow - $weekThen) / $weekThen) * 100, 1)
            : null;

        return [
            'followers' => $current,
            'week_delta' => $delta,
            'week_pct' => $pct,
        ];
    }

    /**
     * @param  list<int|string>  $socialIds
     * @return array{
     *     hashtags: list<array{term: string, count: int}>,
     *     keywords: list<array{term: string, count: int}>,
     *     ctas: list<array{term: string, count: int, lines: list<array{text: string, count: int, post_id: int|null}>}>,
     *     cta_clicks: array{posts_with_cta: int, posts: int},
     *     format_mix: list<array{type: string, count: int}>
     * }
     */
    private function captionIntelForAccounts(User $user, array $socialIds, CarbonImmutable $since): array
    {
        $ids = array_values(array_filter(array_map('intval', $socialIds)));

        if ($ids === []) {
            return [
                'hashtags' => [],
                'keywords' => [],
                'ctas' => [],
                'cta_clicks' => ['posts_with_cta' => 0, 'posts' => 0],
                'format_mix' => [],
            ];
        }

        $posts = Post::query()
            ->whereIn('social_account_id', $ids)
            ->whereNotNull('posted_at')
            ->where('posted_at', '>=', $since)
            ->with(['analysis:id,post_id,cta,status'])
            ->get(['id', 'social_account_id', 'caption', 'type', 'metrics', 'posted_at', 'raw_payload']);

        return $this->competitorInsights->captionIntel($posts);
    }

    /**
     * @param  Collection<int, TrackedAccount>  $visibleAccounts
     * @return list<array<string, mixed>>
     */
    private function recentPostsPayload(
        User $user,
        Collection $visibleAccounts,
        int $limit,
        bool $showHiddenLikes = false,
    ): array {
        $ids = $visibleAccounts->pluck('social_account_id')->filter()->map(fn ($id) => (int) $id)->values()->all();

        if ($ids === []) {
            return [];
        }

        // Over-fetch then filter so hidden-like posts do not crowd out visible
        // ones when the toggle is off (JSON metrics cannot be WHERE'd cleanly).
        $posts = Post::query()
            ->whereIn('social_account_id', $ids)
            ->whereNotNull('posted_at')
            ->with([
                'analysis:id,post_id,status,hook,concept,topics,custom_tags',
                'analysis.terms:id,dimension,slug,label',
                'winnerInsight' => fn ($q) => $q->where('user_id', $user->id)->select(['id', 'post_id', 'user_id', 'score']),
            ])
            ->latest('posted_at')
            ->limit($showHiddenLikes ? $limit : max($limit * 3, 48))
            ->get([
                'id',
                'social_account_id',
                'platform',
                'type',
                'url',
                'caption',
                'media_url',
                'cover_url',
                'media_availability',
                'metrics',
                'posted_at',
            ]);

        if (! $showHiddenLikes) {
            $posts = $posts
                ->reject(fn (Post $post): bool => $this->math->isHiddenLikes($post))
                ->take($limit)
                ->values();
        }

        PostAccountPresenter::attachForUser($posts, $user);

        return $posts->map(function (Post $post): array {
            $analysis = $post->analysis;
            $winner = $post->winnerInsight;
            $metrics = is_array($post->metrics) ? $post->metrics : [];
            $likesHidden = ($metrics['like_count_hidden'] ?? false) === true
                || $this->math->isHiddenLikes($post);

            return [
                'id' => $post->id,
                'platform' => $post->platform instanceof Platform
                    ? $post->platform->value
                    : (string) $post->platform,
                'type' => $post->type instanceof PostType
                    ? $post->type->value
                    : (string) $post->type,
                'url' => $post->url,
                'caption' => $post->caption,
                'media_url' => $post->media_url,
                'cover_url' => $post->cover_url,
                'media_availability' => $post->media_availability,
                'metrics' => [
                    'views' => $metrics['views'] ?? null,
                    'likes' => $likesHidden ? null : ($metrics['likes'] ?? null),
                    'comments' => $metrics['comments'] ?? null,
                    'shares' => $metrics['shares'] ?? null,
                    'like_count_hidden' => $likesHidden,
                ],
                'tracked_account' => $post->getAttribute('tracked_account'),
                'analysis' => $analysis === null ? null : [
                    'status' => $analysis->status?->value ?? (string) $analysis->status,
                    'hook' => $analysis->hook,
                    'concept' => $analysis->concept,
                    'topics' => $analysis->topics,
                    'custom_tags' => $analysis->custom_tags,
                    'term_labels' => $analysis->relationLoaded('terms')
                        ? $this->catalogue->frontendLabels($analysis->terms)
                        : [],
                ],
                'winner_insight' => $winner === null ? null : [
                    'score' => (float) $winner->score,
                ],
            ];
        })->values()->all();
    }

    private function compactNumber(int|float $value): string
    {
        $n = (float) $value;
        $abs = abs($n);

        if ($abs >= 1_000_000) {
            return rtrim(rtrim(number_format($n / 1_000_000, 1), '0'), '.').'M';
        }

        if ($abs >= 1_000) {
            return rtrim(rtrim(number_format($n / 1_000, 1), '0'), '.').'k';
        }

        return (string) (int) round($n);
    }

    private function signedCompact(int $value): string
    {
        $prefix = $value > 0 ? '+' : '';

        return $prefix.$this->compactNumber($value);
    }

    private function signedNumber(float $value): string
    {
        $prefix = $value > 0 ? '+' : '';

        return $prefix.$this->math->round1($value);
    }

    private function compareHint(
        ?float $you,
        ?float $peer,
        string $kind,
        string $youLabel = 'You',
        string $peerLabel = 'peer',
    ): string {
        if ($you === null && $peer === null) {
            return '—';
        }

        $fmt = function (?float $value) use ($kind): string {
            if ($value === null) {
                return '—';
            }

            return match ($kind) {
                'pct' => $this->math->round2($value).'%',
                'views' => $this->compactNumber((int) round($value)),
                default => (string) $this->math->round1($value),
            };
        };

        if ($you !== null && $peer !== null) {
            return "{$youLabel} {$fmt($you)} · {$peerLabel} {$fmt($peer)}";
        }

        if ($you !== null) {
            return "{$youLabel} {$fmt($you)}";
        }

        return "{$peerLabel} {$fmt($peer)}";
    }
}
