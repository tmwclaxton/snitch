<?php

namespace App\Http\Controllers;

use App\Enums\Platform;
use App\Http\Controllers\Concerns\OmitsProductDataWhenPaywalled;
use App\Models\Post;
use App\Models\TrackedAccount;
use App\Models\User;
use App\Models\WinnerInsight;
use App\Services\Billing\PlanEntitlementService;
use App\Services\Competitors\CompetitorInsightsBuilder;
use App\Services\Dashboard\DashboardActivityBuilder;
use App\Services\Tracking\WatchingYouService;
use App\Support\PlatformEmbed;
use App\Support\PostAccountPresenter;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    use OmitsProductDataWhenPaywalled;

    public function __invoke(
        Request $request,
        DashboardActivityBuilder $activity,
        CompetitorInsightsBuilder $insights,
        PlanEntitlementService $entitlements,
        WatchingYouService $watching,
    ): Response {
        $user = $request->user();

        if ($this->productAccessBlocked($user)) {
            return Inertia::render('Dashboard', [
                'stats' => [
                    'tracked_accounts' => 0,
                    'posts' => 0,
                    'winners' => 0,
                    'analysis_backlog' => 0,
                    'analysis_failed' => 0,
                    'last_synced_at' => null,
                    'followers' => 0,
                ],
                'activity' => [
                    'heatmap' => [],
                    'weekly' => [],
                    'by_platform' => [],
                    'by_time_of_day' => [],
                ],
                'insights' => [
                    'engagement' => [
                        'posts' => 0,
                        'avg_views' => 0,
                        'avg_likes' => 0,
                        'avg_comments' => 0,
                        'avg_shares' => 0,
                        'avg_rate' => 0,
                    ],
                    'format_mix' => [],
                    'hashtags' => [],
                    'keywords' => [],
                    'ctas' => [],
                    'cta_clicks' => [
                        'posts_with_cta' => 0,
                        'posts' => 0,
                    ],
                    'growth' => [
                        'followers' => 0,
                        'week_delta' => 0,
                        'week_pct' => null,
                        'month_delta' => 0,
                        'month_pct' => null,
                    ],
                    'ads' => [],
                    'playbook' => [
                        'peak_hour_label' => null,
                        'top_format' => null,
                        'top_hashtag' => null,
                    ],
                ],
                'recent_posts' => [],
                'frames' => $this->frameLimit($request),
                'top_winners' => [],
                'watching' => [
                    'brand' => [],
                    'check' => null,
                ],
                'snitches' => [],
            ]);
        }

        $inQuotaIds = $entitlements->inQuotaTrackedAccountIds($user);
        $socialIds = $this->socialIdsForTrackedAccounts($inQuotaIds);
        $frames = $this->frameLimit($request);

        return Inertia::render('Dashboard', [
            'stats' => fn (): array => $this->stats($user, $inQuotaIds, $socialIds),
            'activity' => fn (): array => $activity->forUser($user),
            'insights' => Inertia::defer(fn (): array => $insights->forUser($user), 'insights'),
            'recent_posts' => fn (): Collection => $this->recentPosts($user, $frames),
            'top_winners' => fn (): Collection => $this->topWinners($user, $socialIds),
            'watching' => fn (): array => [
                'brand' => $watching->forBrandHandles($user),
                'check' => $request->session()->get('watching_check'),
            ],
            'snitches' => fn (): array => $this->snitchFaces($user),
            'frames' => $frames,
        ]);
    }

    /**
     * @param  list<int>  $inQuotaIds
     * @param  list<int>  $socialIds
     * @return array{
     *     tracked_accounts: int,
     *     posts: int,
     *     winners: int,
     *     analysis_backlog: int,
     *     analysis_failed: int,
     *     last_synced_at: mixed,
     *     followers: int
     * }
     */
    private function stats(User $user, array $inQuotaIds, array $socialIds): array
    {
        $postsBase = fn () => Post::query()->forUser($user)->analysisCandidates();

        return [
            'tracked_accounts' => $user->trackedAccounts()->count(),
            'posts' => Post::query()->forUser($user)->count(),
            'winners' => WinnerInsight::query()
                ->where('user_id', $user->id)
                ->when(
                    $socialIds === [],
                    fn ($query) => $query->whereRaw('0 = 1'),
                    fn ($query) => $query->whereHas(
                        'post',
                        fn ($post) => $post->whereIn('social_account_id', $socialIds),
                    ),
                )
                ->count(),
            'analysis_backlog' => $postsBase()->analysisQueue()->count(),
            'analysis_failed' => $postsBase()->analysisFailed()->count(),
            'last_synced_at' => $inQuotaIds === []
                ? null
                : $user->trackedAccounts()->whereIn('id', $inQuotaIds)->max('last_synced_at'),
            'followers' => (int) $user->trackedAccounts()->sum('followers'),
        ];
    }

    /**
     * How many Latest posts to load. The client sets this from the live column count.
     */
    private function frameLimit(Request $request): int
    {
        if ($request->query->has('frames')) {
            $frames = min(24, max(1, $request->integer('frames', 6)));
            $request->session()->put('dashboard_frames', $frames);

            return $frames;
        }

        $remembered = $request->session()->get('dashboard_frames');

        if (is_numeric($remembered)) {
            return min(24, max(1, (int) $remembered));
        }

        return 6;
    }

    /**
     * @return Collection<int, Post>
     */
    private function recentPosts(User $user, int $limit): Collection
    {
        $recentPosts = Post::query()
            ->forUser($user)
            ->with([
                'socialAccount',
                'analysis',
                'winnerInsight' => fn ($q) => $q->where('user_id', $user->id),
            ])
            ->latest('posted_at')
            ->limit($limit)
            ->get();
        PostAccountPresenter::attachForUser($recentPosts, $user);
        $recentPosts->transform(function (Post $post): Post {
            $post->setAttribute(
                'embed',
                PlatformEmbed::resolve($post->platform, $post->url, compact: true),
            );

            return $post;
        });

        return $recentPosts;
    }

    /**
     * @param  list<int>  $socialIds
     * @return Collection<int, WinnerInsight>
     */
    private function topWinners(User $user, array $socialIds): Collection
    {
        $topWinners = WinnerInsight::query()
            ->where('user_id', $user->id)
            ->when(
                $socialIds === [],
                fn ($query) => $query->whereRaw('0 = 1'),
                fn ($query) => $query->whereHas(
                    'post',
                    fn ($post) => $post->whereIn('social_account_id', $socialIds),
                ),
            )
            ->with(['post.socialAccount', 'post.analysis'])
            ->orderByDesc('score')
            ->limit(4)
            ->get();
        PostAccountPresenter::attachForUser($topWinners->pluck('post')->filter(), $user);
        $topWinners->each(function (WinnerInsight $winner): void {
            $post = $winner->post;

            if ($post === null) {
                return;
            }

            $post->setAttribute(
                'embed',
                PlatformEmbed::resolve($post->platform, $post->url, compact: true),
            );
        });

        return $topWinners;
    }

    /**
     * @return list<array{id: int, handle: string, display_name: string|null, avatar: string|null, platform: string}>
     */
    private function snitchFaces(User $user): array
    {
        return $user->trackedAccounts()
            ->orderBy('display_name')
            ->orderBy('handle')
            ->get(['id', 'handle', 'display_name', 'avatar', 'platform'])
            ->map(function (TrackedAccount $account): array {
                $platform = $account->platform;

                return [
                    'id' => $account->id,
                    'handle' => $account->handle,
                    'display_name' => $account->display_name,
                    'avatar' => $account->avatar,
                    'platform' => $platform instanceof Platform ? $platform->value : (string) $platform,
                ];
            })
            ->all();
    }

    /**
     * @param  list<int>  $trackedAccountIds
     * @return list<int>
     */
    private function socialIdsForTrackedAccounts(array $trackedAccountIds): array
    {
        if ($trackedAccountIds === []) {
            return [];
        }

        return TrackedAccount::query()
            ->whereIn('id', $trackedAccountIds)
            ->pluck('social_account_id')
            ->filter()
            ->map(fn (mixed $id): int => (int) $id)
            ->values()
            ->all();
    }
}
