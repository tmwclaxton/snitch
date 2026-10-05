<?php

namespace App\Services\Brief;

use App\Enums\Platform;
use App\Enums\TrackedAccountKind;
use App\Models\BrandProfile;
use App\Models\FollowerSnapshot;
use App\Models\Post;
use App\Models\SocialAd;
use App\Models\TrackedAccount;
use App\Models\User;
use App\Models\WeeklyBrief;
use App\Models\WeeklyBriefIdea;
use App\Services\Dashboard\DashboardMath;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class DailyBriefFactsBuilder
{
    public function __construct(
        private DashboardMath $math,
        private WeeklyBriefGenerator $weekly,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function build(User $user, CarbonImmutable $briefDate): array
    {
        $londonDate = $briefDate->timezone(DashboardMath::TIMEZONE)->startOfDay();
        $yesterday = $londonDate->subDay();
        $window24hStart = $londonDate->subDay();
        $last7 = $londonDate->subDays(7);
        $last14 = $londonDate->subDays(14);
        $last30 = $londonDate->subDays(30);

        $brand = BrandProfile::query()->where('user_id', $user->id)->first();
        $accounts = TrackedAccount::query()
            ->with('socialAccount')
            ->where('user_id', $user->id)
            ->where('platform', Platform::Instagram)
            ->where(function ($query): void {
                $query->where('is_own_account', true)
                    ->orWhere('kind', TrackedAccountKind::Competitor);
            })
            ->orderBy('id')
            ->get();

        $own = $accounts->firstWhere('is_own_account', true);
        $competitors = $accounts->filter(fn (TrackedAccount $account): bool => ! $account->is_own_account)->values();

        $socialIds = $accounts->pluck('social_account_id')->filter()->map(fn ($id): int => (int) $id)->unique()->values()->all();
        $posts = $socialIds === []
            ? collect()
            : Post::query()
                ->with('analysis')
                ->whereIn('social_account_id', $socialIds)
                ->where('posted_at', '>=', $last30->subDays(14))
                ->orderByDesc('posted_at')
                ->get();

        $postsBySocial = $posts->groupBy(fn (Post $post): int => (int) $post->social_account_id);
        $snapshotsBySocial = $this->snapshotsBySocial($socialIds);

        $ownFacts = $own === null
            ? null
            : $this->accountFacts($own, $postsBySocial, $snapshotsBySocial, $londonDate, $yesterday, $window24hStart, $last7, $last14, $last30, true);

        $competitorFacts = $competitors
            ->map(fn (TrackedAccount $account): array => $this->accountFacts(
                $account,
                $postsBySocial,
                $snapshotsBySocial,
                $londonDate,
                $yesterday,
                $window24hStart,
                $last7,
                $last14,
                $last30,
                false,
            ))
            ->all();

        $timing = $this->bestTimes($user, $londonDate);
        $unusedIdeas = $this->unusedWeeklyIdeas($user);
        $formatMix = $this->formatMix($ownFacts, $competitorFacts);
        $ownHandles = $this->ownHandlesFromBrand($brand);
        $handles = array_values(array_unique([
            ...$this->handles($accounts),
            ...$ownHandles,
        ]));

        $facts = [
            'timezone' => DashboardMath::TIMEZONE,
            'brief_date' => $londonDate->toDateString(),
            'brief_date_label' => $londonDate->isoFormat('dddd D MMMM YYYY'),
            'yesterday' => $yesterday->toDateString(),
            'yesterday_label' => $yesterday->isoFormat('dddd D MMMM'),
            'brand' => [
                'name' => $brand?->name,
                'description' => $brand?->description,
                'competitor_brief' => $brand?->competitor_brief,
                'own_handles' => $ownHandles,
            ],
            'allowed_handles' => $handles,
            'own' => $ownFacts,
            'competitors' => $competitorFacts,
            'format_mix' => $formatMix,
            'best_times' => $timing,
            'unused_weekly_ideas' => $unusedIdeas,
            'ads_empty' => $this->allAdsEmpty($competitorFacts),
        ];

        $facts['allowed_post_ids'] = $this->collectPostIds($facts);
        $facts['top_competitor_hit'] = $this->topCompetitorHit($competitorFacts);
        $facts['top_competitor_hit_24h'] = $this->topCompetitorHitLast24h($competitorFacts);
        $facts['cadence'] = $this->cadenceIndex($ownFacts, $competitorFacts);

        return $facts;
    }

    /**
     * @param  Collection<int, TrackedAccount>  $accounts
     * @return list<string>
     */
    private function handles(Collection $accounts): array
    {
        return $accounts
            ->map(fn (TrackedAccount $account): string => $this->normaliseHandle((string) $account->handle))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return list<string>
     */
    private function ownHandlesFromBrand(?BrandProfile $brand): array
    {
        $handles = [];

        foreach ($brand?->own_handles ?? [] as $handle) {
            if (! is_string($handle) && ! is_numeric($handle)) {
                continue;
            }

            $normalised = $this->normaliseHandle((string) $handle);
            if ($normalised !== '') {
                $handles[] = $normalised;
            }
        }

        return array_values(array_unique($handles));
    }

    /**
     * @param  Collection<int, Collection<int, Post>>  $postsBySocial
     * @param  Collection<int, Collection<int, FollowerSnapshot>>  $snapshotsBySocial
     * @return array<string, mixed>
     */
    private function accountFacts(
        TrackedAccount $account,
        Collection $postsBySocial,
        Collection $snapshotsBySocial,
        CarbonImmutable $today,
        CarbonImmutable $yesterday,
        CarbonImmutable $window24hStart,
        CarbonImmutable $last7,
        CarbonImmutable $last14,
        CarbonImmutable $last30,
        bool $isOwn,
    ): array {
        $socialId = $account->social_account_id !== null ? (int) $account->social_account_id : 0;
        $accountPosts = $postsBySocial->get($socialId, collect())->values();
        $snapshots = $snapshotsBySocial->get($socialId, collect());
        $followersNow = $this->latestFollowers($account, $snapshots);
        $change1d = $this->followerChange($snapshots, $followersNow, $today, 1);
        $change7d = $this->followerChange($snapshots, $followersNow, $today, 7);
        $change30d = $this->followerChange($snapshots, $followersNow, $today, 30);

        $posts7 = $accountPosts->filter(fn (Post $post): bool => $this->postedOnOrAfter($post, $last7))->values();
        $posts24h = $accountPosts->filter(fn (Post $post): bool => $this->postedOnOrAfter($post, $window24hStart))->values();
        $postsYesterday = $accountPosts->filter(fn (Post $post): bool => $this->postedOnDate($post, $yesterday))->values();
        $posts30 = $accountPosts->filter(fn (Post $post): bool => $this->postedOnOrAfter($post, $last30))->values();

        $mapped7 = $posts7->map(fn (Post $post): array => $this->mapPost($post, $accountPosts, $account))->values()->all();
        $mapped24h = $posts24h->map(fn (Post $post): array => $this->mapPost($post, $accountPosts, $account))->values()->all();
        $mappedYesterday = $postsYesterday->map(fn (Post $post): array => $this->mapPost($post, $accountPosts, $account))->values()->all();

        $lastPost = $accountPosts->sortByDesc(fn (Post $post) => $post->posted_at?->getTimestampMs() ?? 0)->first();
        $daysSinceLast = null;
        $lastPostedAt = null;

        if ($lastPost instanceof Post && $lastPost->posted_at !== null) {
            $lastLondon = $this->math->toLondon($lastPost->posted_at);
            $lastPostedAt = $lastLondon?->toIso8601String();
            $daysSinceLast = $lastLondon === null ? null : (int) $lastLondon->startOfDay()->diffInDays($today);
        }

        $syncStatus = (string) ($account->last_sync_status ?? '');
        $syncFailed = $syncStatus === 'failed';
        $emptySync = ! $syncFailed
            && $syncStatus === 'empty'
            && $accountPosts->isEmpty()
            && $lastPostedAt === null;
        $quiet = ! $syncFailed && $daysSinceLast !== null && $daysSinceLast >= 14;

        $winners = $this->standoutWinners($account, $accountPosts, $today);
        $ads = $this->activeAds($socialId, $today);

        $best30 = null;
        if ($isOwn) {
            $best30 = $posts30
                ->map(fn (Post $post): array => $this->mapPost($post, $accountPosts, $account))
                ->filter(fn (array $row): bool => $row['times_usual'] !== null)
                ->sortByDesc('times_usual')
                ->first();
        }

        return [
            'tracked_account_id' => (int) $account->id,
            'handle' => $this->normaliseHandle((string) $account->handle),
            'is_own_account' => $isOwn,
            'followers_now' => $followersNow,
            'followers_change_1d' => $change1d,
            'followers_change_7d' => $change7d,
            'followers_change_30d' => $change30d,
            'posts_yesterday_count' => count($mappedYesterday),
            'posts_last_24h_count' => count($mapped24h),
            'posts_last_7d_count' => count($mapped7),
            'posts_last_7d_by_format' => $this->formatCounts($mapped7),
            'posts_last_30d_by_format' => $this->formatCounts(
                $posts30->map(fn (Post $post): array => $this->mapPost($post, $accountPosts, $account))->all()
            ),
            'posts_yesterday' => $mappedYesterday,
            'posts_last_24h' => $mapped24h,
            'posts_last_7d' => $mapped7,
            'days_since_last_post' => $daysSinceLast,
            'last_posted_at' => $lastPostedAt,
            'last_posted_label' => $lastPost instanceof Post ? $this->londonPostLabel($lastPost) : null,
            'best_post_30d' => $best30,
            'standout_winners' => $winners,
            'ads' => $ads,
            'quiet' => $quiet,
            'sync_status' => $syncStatus === '' ? null : $syncStatus,
            'sync_empty' => $emptySync,
            'sync_failed' => $syncFailed,
            'last_synced_at' => $account->last_synced_at?->timezone(DashboardMath::TIMEZONE)->toIso8601String(),
            'cadence' => $this->cadenceForAccount($posts7, $daysSinceLast, $lastPost instanceof Post ? $this->londonPostLabel($lastPost) : null, $today),
        ];
    }

    /**
     * @param  Collection<int, Post>  $accountPosts
     * @return array<string, mixed>
     */
    private function mapPost(Post $post, Collection $accountPosts, TrackedAccount $account): array
    {
        $pi = $this->math->performanceIndex($post, $accountPosts);
        $likes = $this->math->likes($post);
        $hidden = $this->math->isHiddenLikes($post);
        $views = $this->math->views($post);
        $format = $this->math->formatLabel($post);
        $viewsIndex = $hidden && $format === 'Reel' && $views > 0
            ? $this->viewsIndex($post, $accountPosts)
            : null;

        $timesUsual = $pi['pi'] !== null ? $this->math->round1((float) $pi['pi']) : null;
        $timesUsualLabel = $this->timesUsualLabel($timesUsual, $hidden, $viewsIndex);

        $hook = $this->usableHook($post);
        $caption = trim((string) $post->caption);
        $captionExcerpt = $caption === '' ? null : mb_substr($caption, 0, 200);
        $london = $this->math->toLondon($post->posted_at);

        return [
            'post_id' => (int) $post->id,
            'url' => $post->url,
            'handle' => $this->normaliseHandle((string) $account->handle),
            'format' => $format,
            'posted_at' => $london?->toIso8601String(),
            'posted_label' => $this->londonPostLabel($post),
            'caption' => $captionExcerpt,
            'hook' => $hook['text'],
            'hook_kind' => $hook['kind'],
            'likes' => $likes,
            'likes_hidden' => $hidden,
            'comments' => $this->math->comments($post),
            'views' => $views > 0 ? $views : null,
            'times_usual' => $timesUsual,
            'times_usual_label' => $timesUsualLabel,
            'views_vs_usual' => $viewsIndex,
        ];
    }

    /**
     * @param  Collection<int, Post>  $accountPosts
     */
    public function viewsIndex(Post $post, Collection $accountPosts): ?float
    {
        $views = $this->math->views($post);

        if ($views <= 0 || $post->posted_at === null) {
            return null;
        }

        $priors = $accountPosts
            ->filter(function (Post $prior) use ($post): bool {
                if ($prior->id === $post->id || $prior->posted_at === null) {
                    return false;
                }

                if ($this->math->formatLabel($prior) !== 'Reel') {
                    return false;
                }

                if ($this->math->views($prior) <= 0) {
                    return false;
                }

                if ($prior->posted_at->equalTo($post->posted_at)) {
                    return $prior->id < $post->id;
                }

                return $prior->posted_at->lt($post->posted_at);
            })
            ->sortByDesc(fn (Post $prior) => $prior->posted_at?->getTimestampMs() ?? 0)
            ->take(DashboardMath::PI_PRIOR_WINDOW)
            ->values();

        $priorViews = $priors->map(fn (Post $prior): int => $this->math->views($prior))->filter(fn (int $value): bool => $value > 0);
        $median = $this->math->median($priorViews);

        if ($median === null || $median <= 0 || $priorViews->count() < 3) {
            return null;
        }

        return $this->math->round1($views / $median);
    }

    /**
     * @return array{text: ?string, kind: string}
     */
    public function usableHook(Post $post): array
    {
        $analysisHook = trim((string) ($post->analysis?->hook ?? ''));

        if ($analysisHook !== '' && ! $this->looksLikeSceneDescription($analysisHook)) {
            return ['text' => $this->limitWords($analysisHook, 12), 'kind' => 'hook'];
        }

        $opening = $this->firstSentence((string) $post->caption);

        if ($opening === '') {
            return ['text' => null, 'kind' => 'none'];
        }

        return ['text' => $this->limitWords($opening, 12), 'kind' => 'opening_line'];
    }

    public function looksLikeSceneDescription(string $hook): bool
    {
        $text = trim($hook);

        if ($text === '') {
            return true;
        }

        if (str_word_count($text) > 15) {
            return true;
        }

        if (preg_match('/^(A|An|The|Visual|Static|Text overlay)\b/i', $text) === 1) {
            return true;
        }

        if ($this->looksNonEnglish($text)) {
            return true;
        }

        return false;
    }

    /**
     * @param  Collection<int, Post>  $accountPosts
     * @return list<array<string, mixed>>
     */
    private function standoutWinners(TrackedAccount $account, Collection $accountPosts, CarbonImmutable $today): array
    {
        $windows = [
            (int) config('snitch.daily_brief.winner_lookback_days', 7),
            14,
            30,
        ];

        foreach ($windows as $days) {
            $cutoff = $today->subDays($days);
            $hits = $accountPosts
                ->filter(fn (Post $post): bool => $this->postedOnOrAfter($post, $cutoff))
                ->map(fn (Post $post): array => $this->mapPost($post, $accountPosts, $account))
                ->filter(function (array $row): bool {
                    $score = $row['times_usual'] ?? $row['views_vs_usual'];

                    return is_numeric($score) && (float) $score >= DashboardMath::WINNER_THRESHOLD;
                })
                ->sortByDesc(fn (array $row): float => (float) ($row['times_usual'] ?? $row['views_vs_usual'] ?? 0))
                ->values()
                ->all();

            if ($hits !== []) {
                return array_map(function (array $row) use ($days): array {
                    $row['window_days'] = $days;

                    return $row;
                }, $hits);
            }
        }

        return [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function activeAds(int $socialId, CarbonImmutable $today): array
    {
        if ($socialId === 0) {
            return [];
        }

        return SocialAd::query()
            ->where('social_account_id', $socialId)
            ->where('is_active', true)
            ->where('last_seen_at', '>=', $today->subDays(7))
            ->orderByDesc('last_seen_at')
            ->get()
            ->map(fn (SocialAd $ad): array => [
                'title' => $ad->title,
                'url' => $ad->url,
                'last_seen_at' => $ad->last_seen_at?->timezone(DashboardMath::TIMEZONE)->toIso8601String(),
            ])
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function bestTimes(User $user, CarbonImmutable $today): array
    {
        $raw = $this->weekly->bestTimes($user);
        $dayName = DashboardMath::DAYS[((int) $today->dayOfWeekIso) - 1] ?? 'Mon';
        $slots = [];

        foreach ($raw['slots'] as $slot) {
            $sample = $this->slotSampleCount($user, (string) $slot['day'], (int) $slot['hour']);
            $early = $sample < 3;
            $slots[] = [
                'day' => $slot['day'],
                'hour' => $slot['hour'],
                'label' => $slot['label'],
                'score' => $slot['score'],
                'sample_count' => $sample,
                'early_signal' => $early,
                'label_note' => $early ? 'early signal' : null,
            ];
        }

        $todaySlot = collect($slots)->firstWhere('day', $dayName);
        $thin = (bool) ($raw['thin'] ?? true) || $slots === [] || collect($slots)->contains(fn (array $slot): bool => $slot['early_signal']);

        return [
            'slots' => $slots,
            'thin' => $thin,
            'today_weekday' => $dayName,
            'today_slot' => $todaySlot,
            'weekday_evening_block' => $thin ? 'weekday evenings 19:00-21:00' : null,
        ];
    }

    private function slotSampleCount(User $user, string $day, int $hour): int
    {
        $accounts = TrackedAccount::query()->where('user_id', $user->id)->get();
        $socialIds = $accounts->pluck('social_account_id')->filter()->map(fn ($id): int => (int) $id)->all();

        if ($socialIds === []) {
            return 0;
        }

        return Post::query()
            ->whereIn('social_account_id', $socialIds)
            ->whereNotNull('posted_at')
            ->orderByDesc('posted_at')
            ->limit(300)
            ->get()
            ->filter(function (Post $post) use ($day, $hour): bool {
                $bucket = $this->math->londonBucket($post->posted_at);

                if ($bucket === null) {
                    return false;
                }

                return (DashboardMath::DAYS[$bucket['dow']] ?? '') === $day && $bucket['hour'] === $hour;
            })
            ->count();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function unusedWeeklyIdeas(User $user): array
    {
        $weekStart = $this->weekly->currentWeekStart();
        $brief = WeeklyBrief::query()
            ->where('user_id', $user->id)
            ->whereDate('week_start', $weekStart->toDateString())
            ->first();

        if ($brief === null) {
            return [];
        }

        return WeeklyBriefIdea::query()
            ->where('weekly_brief_id', $brief->id)
            ->whereNull('used_at')
            ->orderBy('position')
            ->get()
            ->map(fn (WeeklyBriefIdea $idea): array => [
                'position' => (int) $idea->position,
                'format' => $idea->format,
                'hook' => $idea->hook,
                'caption_angle' => $idea->caption_angle,
            ])
            ->all();
    }

    /**
     * @param  array<string, mixed>|null  $own
     * @param  list<array<string, mixed>>  $competitors
     * @return array<string, mixed>
     */
    private function formatMix(?array $own, array $competitors): array
    {
        $comp7 = [];
        $comp30 = [];

        foreach ($competitors as $row) {
            foreach ($row['posts_last_7d_by_format'] ?? [] as $format => $count) {
                $comp7[$format] = ($comp7[$format] ?? 0) + (int) $count;
            }
            foreach ($row['posts_last_30d_by_format'] ?? [] as $format => $count) {
                $comp30[$format] = ($comp30[$format] ?? 0) + (int) $count;
            }
        }

        return [
            'own_7d' => $own['posts_last_7d_by_format'] ?? [],
            'own_30d' => $own['posts_last_30d_by_format'] ?? [],
            'competitors_7d' => $comp7,
            'competitors_30d' => $comp30,
            'competitor_posts_7d' => array_sum($comp7),
            'competitor_posts_24h' => array_sum(array_map(fn (array $row): int => (int) ($row['posts_last_24h_count'] ?? 0), $competitors)),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $competitors
     * @return array<string, mixed>|null
     */
    private function topCompetitorHit(array $competitors): ?array
    {
        $best = null;

        foreach ($competitors as $row) {
            foreach ($row['standout_winners'] ?? [] as $winner) {
                if ($this->hitScore($winner) > $this->hitScore($best)) {
                    $best = [
                        ...$winner,
                        'handle' => $row['handle'],
                    ];
                }
            }
        }

        return $best;
    }

    /**
     * Best competitor post that is actually inside the last-day window.
     * Do not reuse standout_winners (those can look back 30 days).
     *
     * @param  list<array<string, mixed>>  $competitors
     * @return array<string, mixed>|null
     */
    private function topCompetitorHitLast24h(array $competitors): ?array
    {
        $best = null;

        foreach ($competitors as $row) {
            foreach ($row['posts_last_24h'] ?? [] as $post) {
                if (! is_array($post)) {
                    continue;
                }

                $candidate = [
                    ...$post,
                    'handle' => $row['handle'] ?? ($post['handle'] ?? null),
                ];

                if ($best === null || $this->hitScore($candidate) > $this->hitScore($best)) {
                    $best = $candidate;

                    continue;
                }

                if ($this->hitScore($candidate) === $this->hitScore($best)
                    && strcmp((string) ($candidate['posted_at'] ?? ''), (string) ($best['posted_at'] ?? '')) > 0) {
                    $best = $candidate;
                }
            }
        }

        return $best;
    }

    /**
     * @param  array<string, mixed>|null  $row
     */
    private function hitScore(?array $row): float
    {
        if ($row === null) {
            return -1.0;
        }

        $score = $row['times_usual'] ?? $row['views_vs_usual'] ?? null;

        return is_numeric($score) ? (float) $score : -1.0;
    }

    /**
     * @param  Collection<int, Post>  $posts7
     * @return array<string, mixed>
     */
    private function cadenceForAccount(Collection $posts7, ?int $daysSinceLast, ?string $lastPostedLabel, CarbonImmutable $today): array
    {
        $expected = [];

        for ($i = 7; $i >= 1; $i--) {
            $expected[] = $today->subDays($i)->toDateString();
        }

        $postedDays = $posts7
            ->map(fn (Post $post): ?string => $this->math->toLondon($post->posted_at)?->toDateString())
            ->filter(fn (?string $day): bool => $day !== null && in_array($day, $expected, true))
            ->unique()
            ->values()
            ->all();

        return [
            'posts_last_7d' => $posts7->count(),
            'days_since_last_post' => $daysSinceLast,
            'last_posted_label' => $lastPostedLabel,
            'distinct_days_posted_last_7' => count($postedDays),
            'posted_days_last_7' => $postedDays,
            'posted_every_day_last_7' => count($postedDays) === 7,
            'posted_almost_daily_last_7' => count($postedDays) >= 6,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $own
     * @param  list<array<string, mixed>>  $competitors
     * @return list<array<string, mixed>>
     */
    private function cadenceIndex(?array $own, array $competitors): array
    {
        $rows = [];

        foreach ([$own, ...$competitors] as $row) {
            if (! is_array($row) || ($row['handle'] ?? '') === '') {
                continue;
            }

            $cadence = is_array($row['cadence'] ?? null) ? $row['cadence'] : [];
            $rows[] = [
                'handle' => $row['handle'],
                'posts_last_7d' => (int) ($cadence['posts_last_7d'] ?? $row['posts_last_7d_count'] ?? 0),
                'days_since_last_post' => $cadence['days_since_last_post'] ?? $row['days_since_last_post'] ?? null,
                'distinct_days_posted_last_7' => (int) ($cadence['distinct_days_posted_last_7'] ?? 0),
                'posted_every_day_last_7' => (bool) ($cadence['posted_every_day_last_7'] ?? false),
                'posted_almost_daily_last_7' => (bool) ($cadence['posted_almost_daily_last_7'] ?? ((int) ($cadence['distinct_days_posted_last_7'] ?? 0) >= 6)),
            ];
        }

        return $rows;
    }

    /**
     * @param  list<array<string, mixed>>  $competitors
     */
    private function allAdsEmpty(array $competitors): bool
    {
        foreach ($competitors as $row) {
            if (($row['ads'] ?? []) !== []) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $facts
     * @return list<int>
     */
    private function collectPostIds(array $facts): array
    {
        $ids = [];
        $walker = function (mixed $value) use (&$ids, &$walker): void {
            if (is_array($value)) {
                if (isset($value['post_id']) && is_numeric($value['post_id'])) {
                    $ids[] = (int) $value['post_id'];
                }
                foreach ($value as $child) {
                    $walker($child);
                }
            }
        };
        $walker($facts);

        return array_values(array_unique($ids));
    }

    /**
     * @param  list<int>  $socialIds
     * @return Collection<int, Collection<int, FollowerSnapshot>>
     */
    private function snapshotsBySocial(array $socialIds): Collection
    {
        if ($socialIds === []) {
            return collect();
        }

        return FollowerSnapshot::query()
            ->whereIn('social_account_id', $socialIds)
            ->orderByDesc('captured_on')
            ->get()
            ->groupBy(fn (FollowerSnapshot $snapshot): int => (int) $snapshot->social_account_id);
    }

    /**
     * @param  Collection<int, FollowerSnapshot>  $snapshots
     * @return array{from: int|null, to: int|null, change: int|null, from_date: string|null, available: bool, label: string, value?: string}
     */
    private function followerChange(Collection $snapshots, ?int $now, CarbonImmutable $today, int $days): array
    {
        $target = $today->subDays($days)->toDateString();
        $match = $snapshots->first(function (FollowerSnapshot $snapshot) use ($target): bool {
            return $snapshot->captured_on?->toDateString() === $target;
        });

        if ($match === null && $days === 1) {
            return [
                'from' => null,
                'to' => $now,
                'change' => null,
                'from_date' => null,
                'available' => false,
                'value' => 'New',
                'label' => sprintf(
                    'Daily tracking started %s; first comparison tomorrow',
                    $today->format('j M'),
                ),
            ];
        }

        if ($match === null) {
            $nearest = $snapshots->first(function (FollowerSnapshot $snapshot) use ($today, $days): bool {
                $captured = $snapshot->captured_on?->toDateString();

                return $captured !== null && $captured <= $today->subDays($days)->toDateString();
            });

            if ($nearest === null) {
                return [
                    'from' => null,
                    'to' => $now,
                    'change' => null,
                    'from_date' => null,
                    'available' => false,
                    'label' => 'Not enough history',
                ];
            }

            $from = (int) $nearest->followers;

            return [
                'from' => $from,
                'to' => $now,
                'change' => $now === null ? null : $now - $from,
                'from_date' => $nearest->captured_on?->toDateString(),
                'available' => true,
                'label' => $now === null ? 'Not enough history' : $this->signedChange($now - $from).' since '.$nearest->captured_on?->timezone(DashboardMath::TIMEZONE)->isoFormat('D MMMM'),
            ];
        }

        $from = (int) $match->followers;

        return [
            'from' => $from,
            'to' => $now,
            'change' => $now === null ? null : $now - $from,
            'from_date' => $target,
            'available' => true,
            'label' => $now === null ? 'Not enough history' : $this->signedChange($now - $from).' since '.$match->captured_on?->timezone(DashboardMath::TIMEZONE)->isoFormat('D MMMM'),
        ];
    }

    /**
     * @param  Collection<int, FollowerSnapshot>  $snapshots
     */
    private function latestFollowers(TrackedAccount $account, Collection $snapshots): ?int
    {
        $latest = $snapshots->sortByDesc(fn (FollowerSnapshot $snapshot) => $snapshot->captured_on?->toDateString())->first();

        if ($latest instanceof FollowerSnapshot) {
            return (int) $latest->followers;
        }

        return $account->followers !== null ? (int) $account->followers : null;
    }

    /**
     * @param  list<array<string, mixed>>  $posts
     * @return array<string, int>
     */
    private function formatCounts(array $posts): array
    {
        $counts = [];

        foreach ($posts as $post) {
            $format = (string) ($post['format'] ?? 'Other');
            $counts[$format] = ($counts[$format] ?? 0) + 1;
        }

        return $counts;
    }

    private function timesUsualLabel(?float $pi, bool $hidden, ?float $viewsIndex): string
    {
        if ($pi !== null) {
            return number_format($pi, 1).' times their usual';
        }

        if ($hidden && $viewsIndex !== null) {
            return number_format($viewsIndex, 1).' views vs their usual';
        }

        if ($hidden) {
            return 'likes hidden';
        }

        return 'not enough history';
    }

    private function postedOnOrAfter(Post $post, CarbonImmutable $cutoff): bool
    {
        $london = $this->math->toLondon($post->posted_at);

        return $london !== null && $london->greaterThanOrEqualTo($cutoff);
    }

    private function postedOnDate(Post $post, CarbonImmutable $date): bool
    {
        $london = $this->math->toLondon($post->posted_at);

        return $london !== null && $london->toDateString() === $date->toDateString();
    }

    private function londonPostLabel(Post $post): ?string
    {
        $london = $this->math->toLondon($post->posted_at);

        return $london?->isoFormat('ddd D MMM, HH:mm');
    }

    private function firstSentence(string $caption): string
    {
        $text = trim($caption);

        if ($text === '') {
            return '';
        }

        $firstLine = preg_split('/\R/u', $text, 2)[0] ?? $text;
        $firstLine = trim((string) $firstLine);
        $parts = preg_split('/(?<=[.!?])\s+/u', $firstLine, 2);

        return trim((string) ($parts[0] ?? $firstLine));
    }

    private function limitWords(string $text, int $max): string
    {
        $words = preg_split('/\s+/u', trim($text)) ?: [];

        if (count($words) <= $max) {
            return trim($text);
        }

        return implode(' ', array_slice($words, 0, $max));
    }

    private function looksNonEnglish(string $text): bool
    {
        $lower = mb_strtolower($text);

        if (preg_match('/\b(le|la|les|une|des|pour|avec|dans|vous|nous|est|sont)\b/u', $lower) === 1) {
            return true;
        }

        $letters = preg_replace('/[^a-zA-ZÀ-ÿ]/u', '', $text) ?? '';
        $ascii = preg_replace('/[^a-zA-Z]/', '', $letters) ?? '';

        if ($letters !== '' && $ascii !== '' && (strlen($ascii) / max(1, strlen($letters))) < 0.7) {
            return true;
        }

        return false;
    }

    private function signedChange(int $change): string
    {
        if ($change > 0) {
            return '+'.$change;
        }

        return (string) $change;
    }

    public function normaliseHandle(string $handle): string
    {
        return strtolower(ltrim(trim($handle), '@'));
    }
}
