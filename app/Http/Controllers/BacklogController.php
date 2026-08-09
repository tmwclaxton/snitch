<?php

namespace App\Http\Controllers;

use App\Models\Post;
use App\Services\Billing\PlanEntitlementService;
use App\Support\PlatformEmbed;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class BacklogController extends Controller
{
    public function __construct(private PlanEntitlementService $entitlements) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Post::class);

        $user = $request->user();
        $filter = $request->string('filter')->toString();

        if (! in_array($filter, ['queue', 'failed', 'all'], true)) {
            $filter = 'queue';
        }

        $baseQuery = function () use ($user) {
            $query = Post::query()
                ->where('user_id', $user->id)
                ->reelLike()
                ->with(['trackedAccount', 'analysis', 'winnerInsight']);

            return $this->entitlements->constrainPostsToInQuotaAccounts($query, $user);
        };

        $postsQuery = match ($filter) {
            'failed' => $baseQuery()->analysisFailed(),
            'all' => $baseQuery()->analysisBacklog(),
            default => $baseQuery()->analysisQueue(),
        };

        $posts = $postsQuery
            ->latest('posted_at')
            ->paginate(24)
            ->withQueryString();

        $posts->getCollection()->transform(function (Post $post): Post {
            $post->setAttribute(
                'embed',
                PlatformEmbed::resolve($post->platform, $post->url, compact: true),
            );

            return $post;
        });

        return Inertia::render('backlog/Index', [
            'posts' => $posts,
            'filter' => $filter,
            'counts' => [
                'queue' => $baseQuery()->analysisQueue()->count(),
                'failed' => $baseQuery()->analysisFailed()->count(),
            ],
        ]);
    }
}
