<?php

namespace App\Http\Controllers;

use App\Models\Post;
use App\Models\WinnerInsight;
use App\Services\Billing\PlanEntitlementService;
use App\Services\Dashboard\DashboardActivityBuilder;
use App\Support\PlatformEmbed;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(
        Request $request,
        DashboardActivityBuilder $activity,
        PlanEntitlementService $entitlements,
    ): Response {
        $user = $request->user();
        $inQuotaIds = $entitlements->inQuotaTrackedAccountIds($user);

        $trackedCount = $user->trackedAccounts()->count();
        $lastSyncedAt = $inQuotaIds === []
            ? null
            : $user->trackedAccounts()->whereIn('id', $inQuotaIds)->max('last_synced_at');

        $postsBase = function () use ($user, $entitlements) {
            $query = Post::query()
                ->where('user_id', $user->id)
                ->reelLike();

            return $entitlements->constrainPostsToInQuotaAccounts($query, $user);
        };

        $postsCount = $postsBase()->count();

        $winnersCount = WinnerInsight::query()
            ->where('user_id', $user->id)
            ->when(
                $inQuotaIds === [],
                fn ($query) => $query->whereRaw('0 = 1'),
                fn ($query) => $query->whereHas(
                    'post',
                    fn ($post) => $post->whereIn('tracked_account_id', $inQuotaIds),
                ),
            )
            ->count();

        $analysisBacklog = $postsBase()->analysisQueue()->count();

        $analysisFailed = $postsBase()->analysisFailed()->count();

        $recentPosts = $postsBase()
            ->with(['trackedAccount', 'analysis', 'winnerInsight'])
            ->latest('posted_at')
            ->limit(6)
            ->get()
            ->map(function (Post $post): Post {
                $post->setAttribute(
                    'embed',
                    PlatformEmbed::resolve($post->platform, $post->url, compact: true),
                );

                return $post;
            });

        $topWinners = WinnerInsight::query()
            ->where('user_id', $user->id)
            ->when(
                $inQuotaIds === [],
                fn ($query) => $query->whereRaw('0 = 1'),
                fn ($query) => $query->whereHas(
                    'post',
                    fn ($post) => $post->whereIn('tracked_account_id', $inQuotaIds),
                ),
            )
            ->with(['post.trackedAccount', 'post.analysis'])
            ->orderByDesc('score')
            ->limit(4)
            ->get()
            ->each(function (WinnerInsight $winner): void {
                $post = $winner->post;

                if ($post === null) {
                    return;
                }

                $post->setAttribute(
                    'embed',
                    PlatformEmbed::resolve($post->platform, $post->url, compact: true),
                );
            });

        return Inertia::render('Dashboard', [
            'stats' => [
                'tracked_accounts' => $trackedCount,
                'posts' => $postsCount,
                'winners' => $winnersCount,
                'analysis_backlog' => $analysisBacklog,
                'analysis_failed' => $analysisFailed,
                'last_synced_at' => $lastSyncedAt,
            ],
            'activity' => $activity->forUser($user),
            'recent_posts' => $recentPosts,
            'top_winners' => $topWinners,
        ]);
    }
}
