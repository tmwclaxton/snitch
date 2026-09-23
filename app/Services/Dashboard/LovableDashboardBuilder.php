<?php

namespace App\Services\Dashboard;

use App\Enums\Platform;
use App\Models\Post;
use App\Models\TrackedAccount;
use App\Models\User;
use App\Services\Insights\CompetitorInsights;
use Illuminate\Support\Collection;

class LovableDashboardBuilder
{
    public const MAX_COMPARE = 4;

    public function __construct(private CompetitorInsights $insights) {}

    /**
     * @param  list<string>  $selectedHandles
     * @return array<string, mixed>
     */
    public function forUser(User $user, array $selectedHandles = []): array
    {
        $accounts = $user->trackedAccounts()
            ->competitors()
            ->where('platform', Platform::Instagram)
            ->orderBy('id')
            ->get();

        $own = $accounts->firstWhere('is_own_account', true);
        $rivals = $accounts->filter(fn (TrackedAccount $account): bool => ! $account->is_own_account)->values();

        $socialIds = $accounts->pluck('social_account_id')->filter()->all();
        $posts = $socialIds === []
            ? collect()
            : Post::query()
                ->whereIn('social_account_id', $socialIds)
                ->whereNotNull('posted_at')
                ->orderByDesc('posted_at')
                ->get();

        $rivalIds = $rivals->pluck('social_account_id')->all();
        $rivalPosts = $posts->filter(fn (Post $post): bool => in_array($post->social_account_id, $rivalIds, true))->values();
        $ownPosts = $own === null
            ? collect()
            : $posts->filter(fn (Post $post): bool => $post->social_account_id === $own->social_account_id)->values();

        $insights = $this->insights->compute($rivals, $rivalPosts);
        $suggestedFrequency = $this->insights->suggestedFrequency($rivals, $rivalPosts);

        $normalizedSelected = collect($selectedHandles)
            ->map(fn (string $handle): string => strtolower(ltrim(trim($handle), '@')))
            ->filter()
            ->unique()
            ->values();

        $validSelected = $normalizedSelected
            ->filter(fn (string $handle): bool => $rivals->contains(
                fn (TrackedAccount $account): bool => strtolower((string) $account->handle) === $handle,
            ))
            ->take(self::MAX_COMPARE)
            ->values();

        if ($validSelected->isEmpty()) {
            $validSelected = $rivals->take(self::MAX_COMPARE)
                ->map(fn (TrackedAccount $account): string => strtolower((string) $account->handle))
                ->values();
        }

        $selectedAccounts = $rivals->filter(
            fn (TrackedAccount $account): bool => $validSelected->contains(strtolower((string) $account->handle)),
        )->values();

        $selectedSocialIds = $selectedAccounts->pluck('social_account_id')->all();
        $selectedPosts = $posts->filter(
            fn (Post $post): bool => in_array($post->social_account_id, $selectedSocialIds, true),
        )->values();

        $weekPosts = $this->insights->latestPullWindow($selectedPosts);
        $followersById = $this->insights->followersByAccountId($selectedAccounts);

        return [
            'own_account' => $own === null ? null : $this->accountPayload($own, $posts),
            'rivals' => $rivals->map(fn (TrackedAccount $account) => $this->accountPayload($account, $posts))->values()->all(),
            'selected' => $validSelected->all(),
            'max_compare' => self::MAX_COMPARE,
            'headline' => $this->insights->headline($insights),
            'insights' => $insights,
            'gaps' => $own === null ? [] : $this->insights->ownAccountGaps($insights, $ownPosts, $suggestedFrequency),
            'kpis' => $this->kpis($selectedAccounts, $selectedPosts, $weekPosts, $followersById),
            'compare' => $this->compareRows($selectedAccounts, $posts, $weekPosts),
            'top_posts' => $this->topPosts($selectedAccounts, $weekPosts, $followersById),
            'heatmap' => $this->heatmap($selectedPosts),
            'format_split' => $this->formatSplit($selectedAccounts, $posts),
            'phrases' => $this->phrases($selectedAccounts, $posts),
        ];
    }

    /**
     * @param  Collection<int, Post>  $allPosts
     * @return array<string, mixed>
     */
    private function accountPayload(TrackedAccount $account, Collection $allPosts): array
    {
        return [
            'id' => $account->id,
            'handle' => $account->handle,
            'display_name' => $account->display_name,
            'avatar' => $account->avatar,
            'followers' => (int) ($account->followers ?? 0),
            'is_own_account' => (bool) $account->is_own_account,
            'posts_count' => $allPosts->where('social_account_id', $account->social_account_id)->count(),
            'last_synced_at' => $account->last_synced_at?->toIso8601String(),
        ];
    }

    /**
     * @param  Collection<int, TrackedAccount>  $accounts
     * @param  Collection<int, Post>  $posts
     * @param  Collection<int, Post>  $weekPosts
     * @param  array<int, int>  $followersById
     * @return array{avg_er: float, posts: int, posts_week: int, avg_likes: int, avg_comments: int}
     */
    private function kpis(Collection $accounts, Collection $posts, Collection $weekPosts, array $followersById): array
    {
        $rates = $posts
            ->map(fn (Post $post): float => $this->insights->engagementRate($post, $followersById))
            ->filter(fn (float $er): bool => $er > 0);

        $likes = $posts->sum(fn (Post $post): int => $this->insights->likes($post));
        $comments = $posts->sum(fn (Post $post): int => $this->insights->comments($post));
        $count = $posts->count();

        return [
            'avg_er' => $rates->isEmpty() ? 0.0 : round((float) $rates->avg(), 2),
            'posts' => $count,
            'posts_week' => $weekPosts->count(),
            'avg_likes' => $count > 0 ? (int) round($likes / $count) : 0,
            'avg_comments' => $count > 0 ? (int) round($comments / $count) : 0,
        ];
    }

    /**
     * @param  Collection<int, TrackedAccount>  $accounts
     * @param  Collection<int, Post>  $allPosts
     * @param  Collection<int, Post>  $weekPosts
     * @return list<array<string, mixed>>
     */
    private function compareRows(Collection $accounts, Collection $allPosts, Collection $weekPosts): array
    {
        return $accounts->map(function (TrackedAccount $account) use ($allPosts, $weekPosts): array {
            $cPosts = $allPosts->where('social_account_id', $account->social_account_id);
            $cWeek = $weekPosts->where('social_account_id', $account->social_account_id);
            $followers = (int) ($account->followers ?? 0);
            $rates = $cPosts
                ->map(fn (Post $post): float => $this->insights->engagementRateForFollowers($post, $followers))
                ->filter(fn (float $er): bool => $er > 0);
            $count = $cPosts->count();

            return [
                'handle' => $account->handle,
                'avatar' => $account->avatar,
                'followers' => $followers,
                'avg_er' => $rates->isEmpty() ? 0.0 : round((float) $rates->avg(), 2),
                'posts' => $count,
                'posts_week' => $cWeek->count(),
                'avg_likes' => $count > 0 ? (int) round($cPosts->sum(fn (Post $post): int => $this->insights->likes($post)) / $count) : 0,
                'avg_comments' => $count > 0 ? (int) round($cPosts->sum(fn (Post $post): int => $this->insights->comments($post)) / $count) : 0,
            ];
        })->values()->all();
    }

    /**
     * @param  Collection<int, TrackedAccount>  $accounts
     * @param  Collection<int, Post>  $weekPosts
     * @param  array<int, int>  $followersById
     * @return list<array<string, mixed>>
     */
    private function topPosts(Collection $accounts, Collection $weekPosts, array $followersById): array
    {
        $byHandle = $accounts->keyBy('social_account_id');

        return $weekPosts
            ->sortByDesc(fn (Post $post): int => $this->insights->likes($post) + $this->insights->comments($post))
            ->take(3)
            ->map(function (Post $post) use ($byHandle, $followersById): array {
                $account = $byHandle->get($post->social_account_id);

                return [
                    'id' => $post->id,
                    'handle' => $account?->handle,
                    'caption' => $post->caption,
                    'likes' => $this->insights->likes($post),
                    'comments' => $this->insights->comments($post),
                    'er' => round($this->insights->engagementRate($post, $followersById), 2),
                    'thumbnail_url' => $post->cover_url,
                    'url' => $post->url,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, Post>  $posts
     * @return list<list<int>>
     */
    private function heatmap(Collection $posts): array
    {
        $heat = array_fill(0, 7, array_fill(0, 24, 0));

        foreach ($posts as $post) {
            if ($post->posted_at === null) {
                continue;
            }

            $day = ((int) $post->posted_at->dayOfWeekIso) - 1;
            $hour = (int) $post->posted_at->hour;
            $heat[$day][$hour]++;
        }

        return $heat;
    }

    /**
     * @param  Collection<int, TrackedAccount>  $accounts
     * @param  Collection<int, Post>  $posts
     * @return list<array<string, mixed>>
     */
    private function formatSplit(Collection $accounts, Collection $posts): array
    {
        $since = now()->subDays(30);

        return $accounts->map(function (TrackedAccount $account) use ($posts, $since): array {
            $cPosts = $posts->filter(
                fn (Post $post): bool => $post->social_account_id === $account->social_account_id
                    && $post->posted_at !== null
                    && $post->posted_at->gte($since),
            );

            $reels = $cPosts->filter(fn (Post $post): bool => $this->insights->formatLabel($post->type) === 'Reel')->count();
            $carousels = $cPosts->filter(fn (Post $post): bool => $this->insights->formatLabel($post->type) === 'Carousel')->count();
            $images = $cPosts->filter(fn (Post $post): bool => $this->insights->formatLabel($post->type) === 'Image')->count();

            return [
                'handle' => $account->handle,
                'count' => $cPosts->count(),
                'reels' => $reels,
                'carousels' => $carousels,
                'images' => $images,
            ];
        })->values()->all();
    }

    /**
     * @param  Collection<int, TrackedAccount>  $accounts
     * @param  Collection<int, Post>  $posts
     * @return list<array<string, mixed>>
     */
    private function phrases(Collection $accounts, Collection $posts): array
    {
        return $accounts->map(function (TrackedAccount $account) use ($posts): array {
            $captions = $posts
                ->where('social_account_id', $account->social_account_id)
                ->pluck('caption')
                ->filter()
                ->map(fn (mixed $caption): string => (string) $caption)
                ->all();

            return [
                'handle' => $account->handle,
                'avatar' => $account->avatar,
                ...$this->insights->extractPhrases($captions),
            ];
        })->values()->all();
    }
}
