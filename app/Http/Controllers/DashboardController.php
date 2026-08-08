<?php

namespace App\Http\Controllers;

use App\Enums\AnalysisStatus;
use App\Enums\MediaAvailability;
use App\Models\Post;
use App\Models\WinnerInsight;
use App\Services\Dashboard\DashboardActivityBuilder;
use App\Support\PlatformEmbed;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request, DashboardActivityBuilder $activity): Response
    {
        $user = $request->user();

        $trackedCount = $user->trackedAccounts()->count();
        $lastSyncedAt = $user->trackedAccounts()->max('last_synced_at');

        $postsBase = fn () => Post::query()
            ->where('user_id', $user->id)
            ->reelLike();

        $postsCount = $postsBase()->count();

        $winnersCount = WinnerInsight::query()
            ->where('user_id', $user->id)
            ->count();

        $analysisBacklog = $postsBase()
            ->where('media_availability', MediaAvailability::Available)
            ->where(function ($query): void {
                $query->whereDoesntHave('analysis')
                    ->orWhereHas('analysis', function ($analysis): void {
                        $analysis->whereIn('status', [
                            AnalysisStatus::Pending,
                            AnalysisStatus::Processing,
                        ]);
                    });
            })
            ->count();

        $analysisFailed = $postsBase()
            ->whereHas('analysis', function ($analysis): void {
                $analysis->where('status', AnalysisStatus::Failed);
            })
            ->count();

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
