<?php

namespace App\Services\Competitors;

use App\Enums\PostType;
use App\Models\Post;
use App\Models\TrackedAccount;
use App\Models\User;
use App\Services\Dashboard\DashboardActivityBuilder;
use Illuminate\Support\Collection;

class CompetitorInsightsBuilder
{
    public const TERM_LIMIT = 12;

    /**
     * @var list<string>
     */
    private const STOPWORDS = [
        'the', 'and', 'for', 'you', 'your', 'with', 'this', 'that', 'from', 'are',
        'was', 'were', 'have', 'has', 'had', 'not', 'but', 'our', 'out', 'just',
        'like', 'get', 'got', 'can', 'will', 'all', 'new', 'now', 'how', 'why',
        'what', 'when', 'who', 'its', 'it', 'they', 'them', 'their', 'about',
        'into', 'over', 'after', 'more', 'also', 'than', 'then', 'too', 'via',
        'http', 'https', 'www', 'com',
    ];

    public function __construct(private DashboardActivityBuilder $activity) {}

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
            ->reelLike()
            ->whereNotNull('posted_at')
            ->get(['caption', 'type', 'metrics']);

        return [
            'activity' => $this->activity->forUser($user, $socialAccountId),
            'engagement' => $this->engagement($posts),
            'format_mix' => $this->formatMix($posts),
            'hashtags' => $this->topHashtags($posts),
            'keywords' => $this->topKeywords($posts),
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

            if (preg_match_all('/[\p{L}\p{N}]{3,}/u', mb_strtolower($stripped), $matches) === 0) {
                continue;
            }

            foreach ($matches[0] as $word) {
                if (in_array($word, self::STOPWORDS, true) || is_numeric($word)) {
                    continue;
                }

                $counts[$word] = ($counts[$word] ?? 0) + 1;
            }
        }

        return $this->sortedTerms($counts);
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
