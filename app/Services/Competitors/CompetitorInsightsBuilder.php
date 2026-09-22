<?php

namespace App\Services\Competitors;

use App\Enums\Platform;
use App\Enums\PostType;
use App\Models\FollowerSnapshot;
use App\Models\Post;
use App\Models\SocialAd;
use App\Models\TrackedAccount;
use App\Models\User;
use App\Services\Dashboard\DashboardActivityBuilder;
use App\Services\Tracking\FollowerSnapshotRecorder;
use App\Support\SponsoredPostDetector;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class CompetitorInsightsBuilder
{
    public const TERM_LIMIT = 12;

    /**
     * Function words and caption filler. Keywords should be topic nouns, not glue.
     *
     * @var list<string>
     */
    private const STOPWORDS = [
        'the', 'and', 'for', 'you', 'your', 'with', 'this', 'that', 'from', 'are',
        'was', 'were', 'have', 'has', 'had', 'not', 'but', 'our', 'out', 'just',
        'like', 'get', 'got', 'can', 'will', 'all', 'new', 'now', 'how', 'why',
        'what', 'when', 'who', 'its', 'it', 'they', 'them', 'their', 'about',
        'into', 'over', 'after', 'more', 'also', 'than', 'then', 'too', 'via',
        'http', 'https', 'www', 'com', 'these', 'those', 'there', 'here', 'should',
        'would', 'could', 'shall', 'might', 'must', 'dont', 'donot', 'does', 'doesnt',
        'did', 'didnt', 'cant', 'wont', 'isnt', 'arent', 'wasnt', 'werent', 'youre',
        'theyre', 'weve', 'ive', 'ill', 'theyll', 'lets', 'been', 'being', 'some',
        'any', 'each', 'every', 'very', 'really', 'only', 'even', 'still', 'back',
        'make', 'made', 'take', 'took', 'come', 'came', 'going', 'wanna', 'gonna',
        'yeah', 'yes', 'nope', 'okay', 'ok', 'hey', 'hi', 'hello', 'please', 'thank',
        'thanks', 'much', 'many', 'most', 'such', 'into', 'onto', 'off', 'down',
        'up', 'out', 'own', 'same', 'other', 'another', 'because', 'while', 'where',
        'which', 'whom', 'whose', 'than', 'then', 'once', 'again', 'ever', 'never',
        'always', 'today', 'tonight', 'tomorrow', 'yesterday', 'week', 'month',
        'year', 'day', 'time', 'thing', 'things', 'stuff', 'someone', 'something',
        'everyone', 'everything', 'anyone', 'anything', 'nothing', 'start', 'started',
        'starts', 'say', 'says', 'said', 'tell', 'told', 'talk', 'talking', 'know',
        'knows', 'think', 'thinks', 'look', 'looks', 'see', 'seen', 'want', 'wants',
        'need', 'needs', 'use', 'used', 'using', 'try', 'keep', 'let', 'put', 'give',
        'gave', 'got', 'getting', 'one', 'two', 'first', 'last', 'next', 'best',
        'good', 'great', 'really', 'literally', 'actually', 'basically', 'words',
        'word', 'caption', 'link', 'bio', 'swipe', 'comment', 'comments', 'like',
        'likes', 'share', 'shares', 'follow', 'follows', 'video', 'reel', 'reels',
        'post', 'posts',
    ];

    public function __construct(
        private DashboardActivityBuilder $activity,
        private CtaEssenceGrouper $ctaEssence,
        private SponsoredPostDetector $sponsored,
    ) {}

    /**
     * @return array{
     *     activity: array{
     *         heatmap: list<array{date: string, count: int}>,
     *         weekly: list<array{week_start: string, label: string, count: int}>,
     *         by_platform: list<array{platform: string, count: int}>,
     *         by_time_of_day: list<array{hour: int, label: string, count: int}>
     *     },
     *     engagement: array{
     *         posts: int,
     *         avg_views: float,
     *         avg_likes: float,
     *         avg_comments: float,
     *         avg_shares: float,
     *         avg_rate: float
     *     },
     *     format_mix: list<array{type: string, count: int}>,
     *     hashtags: list<array{term: string, count: int}>,
     *     keywords: list<array{term: string, count: int}>
     * }
     */
    public function forAccount(User $user, TrackedAccount $account): array
    {
        $socialAccountId = $account->social_account_id;

        $posts = Post::query()
            ->where('social_account_id', $socialAccountId)
            ->whereNotNull('posted_at')
            ->with(['analysis.terms'])
            ->get(['id', 'social_account_id', 'caption', 'type', 'metrics', 'posted_at', 'raw_payload']);

        return [
            'activity' => $this->activity->forUser($user, $socialAccountId),
            ...$this->summarise($posts),
            'growth' => $this->growth($user, $socialAccountId),
            'follower_series' => $this->followerSeries($socialAccountId),
            'paid_vs_organic' => $this->paidVsOrganic($posts, [$socialAccountId]),
            'ads' => $this->ads([$socialAccountId]),
        ];
    }

    /**
     * Corpus-level Build 1 board for the dashboard (all in-quota snitches).
     *
     * @return array{
     *     engagement: array{posts: int, avg_views: float, avg_likes: float, avg_comments: float, avg_shares: float, avg_rate: float},
     *     format_mix: list<array{type: string, count: int}>,
     *     hashtags: list<array{term: string, count: int}>,
     *     keywords: list<array{term: string, count: int}>,
     *     ctas: list<array{term: string, count: int, lines: list<array{text: string, count: int, post_id: int|null}>}>,
     *     playbook: array{peak_hour_label: string|null, top_format: string|null, top_hashtag: string|null}
     * }
     */
    public function forUser(User $user): array
    {
        $posts = Post::query()
            ->forUser($user)
            ->whereNotNull('posted_at')
            ->with(['analysis.terms'])
            ->get(['id', 'social_account_id', 'caption', 'type', 'metrics', 'posted_at', 'raw_payload']);

        $summary = $this->summarise($posts);
        $activity = $this->activity->forUser($user);

        $socialIds = TrackedAccount::query()
            ->where('user_id', $user->id)
            ->whereNotNull('social_account_id')
            ->pluck('social_account_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        return [
            ...$summary,
            'playbook' => $this->playbook($activity['by_time_of_day'] ?? [], $summary['format_mix'], $summary['hashtags']),
            'growth' => $this->growth($user),
            'follower_series' => $this->followerSeriesForUser($socialIds),
            'paid_vs_organic' => $this->paidVsOrganic($posts, $socialIds),
            'ads' => $this->ads($socialIds),
        ];
    }

    /**
     * @param  Collection<int, Post>  $posts
     * @return array{
     *     engagement: array{posts: int, avg_views: float, avg_likes: float, avg_comments: float, avg_shares: float, avg_rate: float},
     *     format_mix: list<array{type: string, count: int}>,
     *     hashtags: list<array{term: string, count: int}>,
     *     keywords: list<array{term: string, count: int}>,
     *     ctas: list<array{term: string, count: int, lines: list<array{text: string, count: int, post_id: int|null}>}>
     * }
     */
    private function summarise(Collection $posts): array
    {
        return [
            'engagement' => $this->engagement($posts),
            'format_mix' => $this->formatMix($posts),
            'hashtags' => $this->topHashtags($posts),
            'keywords' => $this->topKeywords($posts),
            'ctas' => $this->topCtas($posts),
            'cta_clicks' => $this->ctaClicks($posts),
        ];
    }

    /**
     * @param  Collection<int, Post>  $posts
     * @return array{posts: int, avg_views: float, avg_likes: float, avg_comments: float, avg_shares: float, avg_rate: float}
     */
    private function engagement(Collection $posts): array
    {
        $count = $posts->count();

        if ($count === 0) {
            return [
                'posts' => 0,
                'avg_views' => 0.0,
                'avg_likes' => 0.0,
                'avg_comments' => 0.0,
                'avg_shares' => 0.0,
                'avg_rate' => 0.0,
            ];
        }

        $views = 0.0;
        $likes = 0.0;
        $comments = 0.0;
        $shares = 0.0;
        $rateSum = 0.0;

        foreach ($posts as $post) {
            $metrics = is_array($post->metrics) ? $post->metrics : [];
            $postViews = (float) ($metrics['views'] ?? 0);
            $postLikes = (float) ($metrics['likes'] ?? 0);
            $postComments = (float) ($metrics['comments'] ?? 0);
            $postShares = (float) ($metrics['shares'] ?? 0);

            $views += $postViews;
            $likes += $postLikes;
            $comments += $postComments;
            $shares += $postShares;
            $rateSum += $postViews > 0
                ? (($postLikes + $postComments + $postShares) / $postViews) * 100
                : 0.0;
        }

        return [
            'posts' => $count,
            'avg_views' => round($views / $count, 1),
            'avg_likes' => round($likes / $count, 1),
            'avg_comments' => round($comments / $count, 1),
            'avg_shares' => round($shares / $count, 1),
            'avg_rate' => round($rateSum / $count, 2),
        ];
    }

    /**
     * @param  Collection<int, Post>  $posts
     * @return list<array{type: string, count: int}>
     */
    private function formatMix(Collection $posts): array
    {
        $counts = [];

        foreach ($posts as $post) {
            $type = $post->type instanceof PostType
                ? $post->type->value
                : (string) $post->type;

            $counts[$type] = ($counts[$type] ?? 0) + 1;
        }

        arsort($counts);

        $result = [];

        foreach ($counts as $type => $count) {
            $result[] = [
                'type' => $type,
                'count' => $count,
            ];
        }

        return $result;
    }

    /**
     * @param  Collection<int, Post>  $posts
     * @return list<array{term: string, count: int}>
     */
    private function topHashtags(Collection $posts): array
    {
        $counts = [];

        foreach ($posts as $post) {
            $caption = (string) ($post->caption ?? '');

            if ($caption === '') {
                continue;
            }

            if (preg_match_all('/#([\p{L}\p{N}_]+)/u', $caption, $matches) < 1) {
                continue;
            }

            foreach ($matches[1] as $tag) {
                $term = mb_strtolower((string) $tag);

                if ($term === '') {
                    continue;
                }

                $counts[$term] = ($counts[$term] ?? 0) + 1;
            }
        }

        return $this->sortedTerms($counts);
    }

    /**
     * @param  Collection<int, Post>  $posts
     * @return list<array{term: string, count: int}>
     */
    private function topKeywords(Collection $posts): array
    {
        $counts = [];

        foreach ($posts as $post) {
            $caption = (string) ($post->caption ?? '');

            if ($caption === '') {
                continue;
            }

            $stripped = preg_replace('/https?:\/\/\S+/u', ' ', $caption) ?? $caption;
            $stripped = preg_replace('/#([\p{L}\p{N}_]+)/u', ' ', $stripped) ?? $stripped;
            $stripped = preg_replace('/@[\p{L}\p{N}_.]+/u', ' ', $stripped) ?? $stripped;

            if (preg_match_all('/[\p{L}\p{N}]{4,}/u', mb_strtolower($stripped), $matches) === 0) {
                continue;
            }

            foreach ($matches[0] as $word) {
                if ($this->isNoiseKeyword($word)) {
                    continue;
                }

                $counts[$word] = ($counts[$word] ?? 0) + 1;
            }
        }

        return $this->sortedTerms($counts);
    }

    /**
     * @param  Collection<int, Post>  $posts
     * @return list<array{term: string, count: int, lines: list<array{text: string, count: int, post_id: int|null}>}>
     */
    private function topCtas(Collection $posts): array
    {
        $counts = [];
        $postIds = [];

        foreach ($posts->sortByDesc(fn (Post $post) => $post->posted_at?->getTimestamp() ?? 0) as $post) {
            $cta = trim((string) ($post->analysis?->cta ?? ''));

            if ($cta === '' || strcasecmp($cta, 'No explicit CTA') === 0) {
                continue;
            }

            $term = mb_strtolower($cta);

            if (mb_strlen($term) > 280) {
                $term = mb_substr($term, 0, 277).'...';
            }

            $counts[$term] = ($counts[$term] ?? 0) + 1;
            $postIds[$term] ??= $post->id;
        }

        $grouped = $this->ctaEssence->group($counts);

        foreach ($grouped as $index => $row) {
            foreach ($row['lines'] as $lineIndex => $line) {
                $key = mb_strtolower($line['text']);
                $grouped[$index]['lines'][$lineIndex]['post_id'] = $postIds[$key] ?? null;
            }
        }

        return $grouped;
    }

    /**
     * @param  Collection<int, Post>  $posts
     * @return array{posts_with_cta: int, posts: int}
     */
    private function ctaClicks(Collection $posts): array
    {
        $withCta = 0;

        foreach ($posts as $post) {
            $cta = trim((string) ($post->analysis?->cta ?? ''));

            if ($cta !== '' && strcasecmp($cta, 'No explicit CTA') !== 0) {
                $withCta++;
            }
        }

        return [
            'posts_with_cta' => $withCta,
            'posts' => $posts->count(),
        ];
    }

    /**
     * @return array{
     *     followers: int,
     *     week_delta: int|null,
     *     week_pct: float|null,
     *     month_delta: int|null,
     *     month_pct: float|null,
     *     since_first_delta: int|null,
     *     since_first_pct: float|null
     * }
     */
    private function growth(User $user, ?int $socialAccountId = null): array
    {
        $ids = $socialAccountId !== null
            ? [$socialAccountId]
            : TrackedAccount::query()
                ->where('user_id', $user->id)
                ->whereNotNull('social_account_id')
                ->pluck('social_account_id')
                ->map(fn (mixed $id): int => (int) $id)
                ->unique()
                ->values()
                ->all();

        $this->seedSnapshots($user, $ids);

        $today = CarbonImmutable::now()->toDateString();
        $week = CarbonImmutable::now()->subDays(7)->toDateString();
        $month = CarbonImmutable::now()->subDays(30)->toDateString();

        $current = 0;
        $weekNow = 0;
        $weekThen = 0;
        $weekMatched = false;
        $monthNow = 0;
        $monthThen = 0;
        $monthMatched = false;

        $asOf = $this->followersAsOf($ids, [$today, $week, $month]);

        foreach ($ids as $id) {
            $now = $asOf[$id][$today] ?? null;
            $weekAgo = $asOf[$id][$week] ?? null;
            $monthAgo = $asOf[$id][$month] ?? null;

            if ($now !== null) {
                $current += $now;
            }

            if ($now !== null && $weekAgo !== null) {
                $weekMatched = true;
                $weekNow += $now;
                $weekThen += $weekAgo;
            }

            if ($now !== null && $monthAgo !== null) {
                $monthMatched = true;
                $monthNow += $now;
                $monthThen += $monthAgo;
            }
        }

        [$sinceFirstDelta, $sinceFirstPct] = $this->sinceFirstGrowth($ids, $today, $current);

        return [
            'followers' => $current,
            'week_delta' => $weekMatched ? $weekNow - $weekThen : null,
            'week_pct' => $weekMatched ? $this->pct($weekNow, $weekThen) : null,
            'month_delta' => $monthMatched ? $monthNow - $monthThen : null,
            'month_pct' => $monthMatched ? $this->pct($monthNow, $monthThen) : null,
            'since_first_delta' => $sinceFirstDelta,
            'since_first_pct' => $sinceFirstPct,
        ];
    }

    /**
     * @param  list<int>  $socialAccountIds
     * @return array{0: int|null, 1: float|null}
     */
    private function sinceFirstGrowth(array $socialAccountIds, string $today, int $current): array
    {
        if ($socialAccountIds === [] || $current <= 0) {
            return [null, null];
        }

        $firstDay = FollowerSnapshot::query()
            ->whereIn('social_account_id', $socialAccountIds)
            ->min('captured_on');

        if (! is_string($firstDay) || $firstDay === '' || $firstDay >= $today) {
            return [null, null];
        }

        $firstDay = CarbonImmutable::parse($firstDay)->toDateString();
        $asOf = $this->followersAsOf($socialAccountIds, [$firstDay]);
        $then = 0;
        $matched = false;

        foreach ($socialAccountIds as $id) {
            if (isset($asOf[$id][$firstDay])) {
                $then += $asOf[$id][$firstDay];
                $matched = true;
            }
        }

        if (! $matched) {
            return [null, null];
        }

        return [$current - $then, $this->pct($current, $then)];
    }

    /**
     * @return list<array{captured_on: string, label: string, followers: int}>
     */
    private function followerSeries(?int $socialAccountId): array
    {
        if ($socialAccountId === null) {
            return [];
        }

        return $this->followerSeriesForUser([$socialAccountId]);
    }

    /**
     * @param  list<int>  $socialAccountIds
     * @return list<array{captured_on: string, label: string, followers: int}>
     */
    private function followerSeriesForUser(array $socialAccountIds): array
    {
        if ($socialAccountIds === []) {
            return [];
        }

        $dates = FollowerSnapshot::query()
            ->whereIn('social_account_id', $socialAccountIds)
            ->orderBy('captured_on')
            ->pluck('captured_on')
            ->map(fn (mixed $day): string => CarbonImmutable::parse((string) $day)->toDateString())
            ->unique()
            ->values()
            ->all();

        if ($dates === []) {
            return [];
        }

        if (count($dates) > 104) {
            $dates = array_slice($dates, -104);
        }

        $asOf = $this->followersAsOf($socialAccountIds, $dates);
        $points = [];

        foreach ($dates as $date) {
            $sum = 0;
            $any = false;

            foreach ($socialAccountIds as $id) {
                if (! isset($asOf[$id][$date])) {
                    continue;
                }

                $sum += $asOf[$id][$date];
                $any = true;
            }

            if (! $any) {
                continue;
            }

            $points[] = [
                'captured_on' => $date,
                'label' => CarbonImmutable::parse($date)->format('j M'),
                'followers' => $sum,
            ];
        }

        return $points;
    }

    /**
     * @param  Collection<int, Post>  $posts
     * @param  list<int|null>  $socialAccountIds
     * @return array{organic: int, sponsored: int, running_ads: int}
     */
    private function paidVsOrganic(Collection $posts, array $socialAccountIds): array
    {
        $sponsored = 0;
        $organic = 0;

        foreach ($posts as $post) {
            if ($this->sponsored->looksSponsored($post)) {
                $sponsored++;
            } else {
                $organic++;
            }
        }

        $ids = array_values(array_filter(
            array_map(static fn (mixed $id): int => (int) $id, $socialAccountIds),
            static fn (int $id): bool => $id > 0,
        ));

        $runningAds = $ids === []
            ? 0
            : SocialAd::query()
                ->whereIn('social_account_id', $ids)
                ->where('is_active', true)
                ->count();

        return [
            'organic' => $organic,
            'sponsored' => $sponsored,
            'running_ads' => $runningAds,
        ];
    }

    /**
     * @param  list<int>  $socialAccountIds
     */
    private function seedSnapshots(User $user, array $socialAccountIds): void
    {
        if ($socialAccountIds === []) {
            return;
        }

        $recorder = app(FollowerSnapshotRecorder::class);
        $have = FollowerSnapshot::query()
            ->whereIn('social_account_id', $socialAccountIds)
            ->pluck('social_account_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->all();

        $accounts = TrackedAccount::query()
            ->where('user_id', $user->id)
            ->whereIn('social_account_id', $socialAccountIds)
            ->whereNotNull('followers')
            ->get(['social_account_id', 'followers']);

        foreach ($accounts as $account) {
            $id = (int) $account->social_account_id;
            $followers = (int) $account->followers;

            if (! in_array($id, $have, true)) {
                $recorder->record($id, $followers);
            } else {
                $recorder->ensureBaselines($id, $followers);
            }
        }
    }

    /**
     * @param  list<int>  $socialAccountIds
     * @param  list<string>  $dates
     * @return array<int, array<string, int>>
     */
    private function followersAsOf(array $socialAccountIds, array $dates): array
    {
        if ($socialAccountIds === [] || $dates === []) {
            return [];
        }

        $rows = FollowerSnapshot::query()
            ->whereIn('social_account_id', $socialAccountIds)
            ->whereDate('captured_on', '<=', max($dates))
            ->orderByDesc('captured_on')
            ->get(['social_account_id', 'captured_on', 'followers']);

        $found = [];

        foreach ($rows as $row) {
            $id = (int) $row->social_account_id;
            $day = $row->captured_on?->toDateString();

            if ($day === null) {
                continue;
            }

            foreach ($dates as $date) {
                if (isset($found[$id][$date]) || $day > $date) {
                    continue;
                }

                $found[$id][$date] = (int) $row->followers;
            }
        }

        return $found;
    }

    private function pct(int $current, int $previous): ?float
    {
        if ($previous <= 0) {
            return null;
        }

        return round((($current - $previous) / $previous) * 100, 1);
    }

    /**
     * @param  list<int>  $socialAccountIds
     * @return list<array{id: int, title: string, body: string|null, url: string, platform: string}>
     */
    private function ads(array $socialAccountIds): array
    {
        if ($socialAccountIds === []) {
            return [];
        }

        return SocialAd::query()
            ->whereIn('social_account_id', $socialAccountIds)
            ->where('is_active', true)
            ->latest('last_seen_at')
            ->limit(8)
            ->get()
            ->map(fn (SocialAd $ad): array => [
                'id' => $ad->id,
                'title' => $ad->title,
                'body' => $ad->body,
                'url' => $ad->url,
                'platform' => $ad->platform instanceof Platform
                    ? $ad->platform->value
                    : (string) $ad->platform,
            ])
            ->all();
    }

    /**
     * @param  list<array{hour: int, label: string, count: int}>  $hours
     * @param  list<array{type: string, count: int}>  $mix
     * @param  list<array{term: string, count: int}>  $hashtags
     * @return array{peak_hour_label: string|null, top_format: string|null, top_hashtag: string|null}
     */
    private function playbook(array $hours, array $mix, array $hashtags): array
    {
        $peak = null;
        $peakCount = 0;

        foreach ($hours as $row) {
            if ($row['count'] > $peakCount) {
                $peakCount = $row['count'];
                $peak = $row['label'];
            }
        }

        return [
            'peak_hour_label' => $peakCount > 0 ? $peak : null,
            'top_format' => $mix[0]['type'] ?? null,
            'top_hashtag' => $hashtags[0]['term'] ?? null,
        ];
    }

    private function isNoiseKeyword(string $word): bool
    {
        if (is_numeric($word) || mb_strlen($word) < 4) {
            return true;
        }

        return in_array($word, self::STOPWORDS, true);
    }

    /**
     * @param  array<string, int>  $counts
     * @return list<array{term: string, count: int}>
     */
    private function sortedTerms(array $counts): array
    {
        arsort($counts);

        $result = [];

        foreach (array_slice($counts, 0, self::TERM_LIMIT, true) as $term => $count) {
            $result[] = [
                'term' => $term,
                'count' => $count,
            ];
        }

        return $result;
    }
}
