<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\OmitsProductDataWhenPaywalled;
use App\Models\Post;
use App\Models\TrackedAccount;
use App\Models\User;
use App\Models\WinnerInsight;
use App\Services\Billing\PlanEntitlementService;
use App\Services\Dashboard\DashboardActivityBuilder;
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
        PlanEntitlementService $entitlements,
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
                ],
                'activity' => [
                    'heatmap' => [],
                    'weekly' => [],
                    'by_platform' => [],
                    'by_time_of_day' => [],
                ],
                'recent_posts' => [],
                'top_winners' => [],
            ]);
        }

        $inQuotaIds = $entitlements->inQuotaTrackedAccountIds($user);
        $socialIds = $this->socialIdsForTrackedAccounts($inQuotaIds);

        $trackedCount = $user->trackedAccounts()->count();
        $lastSyncedAt = $inQuotaIds === []
            ? null
            : $user->trackedAccounts()->whereIn('id', $inQuotaIds)->max('last_synced_at');

        $postsBase = fn () => Post::query()->forUser($user)->reelLike();

        $postsCount = $postsBase()->count();

        $winnersCount = WinnerInsight::query()
            ->where('user_id', $user->id)
            ->when(
                $socialIds === [],
                fn ($query) => $query->whereRaw('0 = 1'),
                fn ($query) => $query->whereHas(
                    'post',
                    fn ($post) => $post->whereIn('social_account_id', $socialIds),
                ),
            )
            ->count();

        $analysisBacklog = $postsBase()->analysisQueue()->count();

        $analysisFailed = $postsBase()->analysisFailed()->count();

        return Inertia::render('Dashboard', [
            'stats' => [
                'tracked_accounts' => $trackedCount,
                'posts' => $postsCount,
                'winners' => $winnersCount,
                'analysis_backlog' => $analysisBacklog,
                'analysis_failed' => $analysisFailed,
                'last_synced_at' => $lastSyncedAt,
            ],
            'activity' => Inertia::defer(fn () => $activity->forUser($user), 'activity'),
            'recent_posts' => Inertia::defer(
                fn () => $this->recentPosts($user),
                'content',
            ),
            'top_winners' => Inertia::defer(
                fn () => $this->topWinners($user, $socialIds),
                'content',
            ),
        ]);
    }

    /**
     * @return Collection<int, Post>
     */
    private function recentPosts(User $user): Collection
    {
        $recentPosts = Post::query()
            ->forUser($user)
            ->reelLike()
            ->with([
                'socialAccount',
                'analysis',
                'winnerInsight' => fn ($q) => $q->where('user_id', $user->id),
            ])
            ->latest('posted_at')
            ->limit(6)
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
