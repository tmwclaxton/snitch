<?php

namespace App\Http\Controllers;

use App\Models\Post;
use App\Support\PlatformEmbed;
use App\Support\PostAccountPresenter;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class BacklogController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Post::class);

        $user = $request->user();
        $filter = $request->string('filter')->toString();

        if (! in_array($filter, ['queue', 'failed', 'all'], true)) {
            $filter = 'queue';
        }

        $baseQuery = fn () => Post::query()
            ->forUser($user)
            ->reelLike()
            ->with([
                'socialAccount',
                'analysis',
                'winnerInsight' => fn ($q) => $q->where('user_id', $user->id),
            ]);

        $postsQuery = match ($filter) {
            'failed' => $baseQuery()->analysisFailed(),
            'all' => $baseQuery()->analysisBacklog(),
            default => $baseQuery()->analysisQueue(),
        };

        $posts = $postsQuery
            ->latest('posted_at')
            ->paginate(24)
            ->withQueryString();

        PostAccountPresenter::attachForUser($posts->getCollection(), $user);
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
