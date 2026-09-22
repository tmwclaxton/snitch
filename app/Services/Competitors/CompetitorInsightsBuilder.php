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
            ->with('analysis')
            ->get(['id', 'social_account_id', 'caption', 'type', 'metrics', 'raw_payload']);

        return [
            'activity' => $this->activity->forUser($user, $socialAccountId),
            ...$this->summarise($posts),
            'growth' => $this->growth($user, $socialAccountId),
            'follower_series' => $this->followerSeries($socialAccountId),
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
     *     ctas: list<array{term: string, count: int, lines: list<array{text: string, count: int}>}>,
     *     playbook: array{peak_hour_label: string|null, top_format: string|null, top_hashtag: string|null}
     * }
     */
    public function forUser(User $user): array
    {
        $posts = Post::query()
            ->forUser($user)
            ->whereNotNull('posted_at')
            ->with('analysis')
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
     *     ctas: list<array{term: string, count: int, lines: list<array{text: string, count: int}>}>
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
     * @return list<array{term: string, count: int, lines: list<array{text: string, count: int}>}>
     */
    private function topCtas(Collection $posts): array
    {
        $counts = [];

        foreach ($posts as $post) {
            $cta = trim((string) ($post->analysis?->cta ?? ''));

            if ($cta === '' || strcasecmp($cta, 'No explicit CTA') === 0) {
                continue;
            }

            $term = mb_strtolower($cta);

            if (mb_strlen($term) > 280) {
                $term = mb_substr($term, 0, 277).'...';
            }

            $counts[$term] = ($counts[$term] ?? 0) + 1;
        }

        return $this->ctaEssence->group($counts);
    }

    /**
     * @param  Collection<int, Post>  $posts
     * @return array{clicks: int, posts_with_cta: int, posts: int}
     */
    private function ctaClicks(Collection $posts): array
    {
        $clicks = 0;
        $withCta = 0;

        foreach ($posts as $post) {
            $clicks += $this->clicksFromPost($post);

            $cta = trim((string) ($post->analysis?->cta ?? ''));

            if ($cta !== '' && strcasecmp($cta, 'No explicit CTA') !== 0) {
                $withCta++;
            }
        }

        return [
            'clicks' => $clicks,
            'posts_with_cta' => $withCta,
            'posts' => $posts->count(),
        ];
    }

    private function clicksFromPost(Post $post): int
    {
        $metrics = is_array($post->metrics) ? $post->metrics : [];

        if (isset($metrics['clicks']) && is_numeric($metrics['clicks'])) {
            return max(0, (int) $metrics['clicks']);
        }

        $raw = is_array($post->raw_payload) ? $post->raw_payload : [];

        foreach (['clicks', 'linkClicks', 'link_clicks', 'clicksCount', 'ctaClicks', 'cta_clicks'] as $key) {
            if (isset($raw[$key]) && is_numeric($raw[$key])) {
                return max(0, (int) $raw[$key]);
            }
        }

        return 0;
    }

    /**
     * @return array{followers: int, week_delta: int|null, week_pct: float|null, month_delta: int|null, month_pct: float|null}
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

        foreach ($ids as $id) {
            $now = $this->latestFollowersOnOrBefore($id, $today);
            $weekAgo = $this->latestFollowersOnOrBefore($id, $week);
            $monthAgo = $this->latestFollowersOnOrBefore($id, $month);

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

        return [
            'followers' => $current,
            'week_delta' => $weekMatched ? $weekNow - $weekThen : null,
            'week_pct' => $weekMatched ? $this->pct($weekNow, $weekThen) : null,
            'month_delta' => $monthMatched ? $monthNow - $monthThen : null,
            'month_pct' => $monthMatched ? $this->pct($monthNow, $monthThen) : null,
        ];
    }

    /**
     * @return list<array{captured_on: string, label: string, followers: int}>
     */
    private function followerSeries(?int $socialAccountId): array
    {
        if ($socialAccountId === null) {
            return [];
        }

        return FollowerSnapshot::query()
            ->where('social_account_id', $socialAccountId)
            ->orderBy('captured_on')
            ->limit(104)
            ->get(['captured_on', 'followers'])
            ->map(fn (FollowerSnapshot $snapshot): array => [
                'captured_on' => $snapshot->captured_on->toDateString(),
                'label' => $snapshot->captured_on->format('j M'),
                'followers' => (int) $snapshot->followers,
            ])
            ->all();
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

            if (in_array($id, $have, true)) {
                continue;
            }

            $recorder->record($id, (int) $account->followers);
        }
    }

    private function latestFollowersOnOrBefore(int $socialAccountId, string $date): ?int
    {
        $followers = FollowerSnapshot::query()
            ->where('social_account_id', $socialAccountId)
            ->whereDate('captured_on', '<=', $date)
            ->orderByDesc('captured_on')
            ->value('followers');

        return $followers === null ? null : (int) $followers;
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
