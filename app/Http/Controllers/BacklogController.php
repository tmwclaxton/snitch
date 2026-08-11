<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\OmitsProductDataWhenPaywalled;
use App\Models\Post;
use App\Models\User;
use App\Support\PlatformEmbed;
use App\Support\PostAccountPresenter;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class BacklogController extends Controller
{
    use OmitsProductDataWhenPaywalled;

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Post::class);

        $user = $request->user();
        $filter = $request->string('filter')->toString();

        if (! in_array($filter, ['queue', 'failed', 'all'], true)) {
            $filter = 'queue';
        }

        if ($this->productAccessBlocked($user)) {
            return Inertia::render('backlog/Index', [
                'posts' => $this->emptyProductPaginator(),
                'filter' => $filter,
                'counts' => [
                    'queue' => 0,
                    'failed' => 0,
                ],
            ]);
        }

        return Inertia::render('backlog/Index', [
            'posts' => Inertia::defer(fn () => $this->paginatedPosts($user, $filter)),
            'filter' => $filter,
            'counts' => [
                'queue' => $this->baseQuery($user)->analysisQueue()->count(),
                'failed' => $this->baseQuery($user)->analysisFailed()->count(),
            ],
        ]);
    }

    /**
     * Shared query builder for the analyse backlog page.
     *
     * Eager-loads the relations the queue cards render and scopes to the user's posts,
     * limited to reel-like content. Called from the deferred posts resolver and both
     * queue/failed count queries so those never diverge.
     */
    private function baseQuery(User $user): Builder
    {
        return Post::query()
            ->forUser($user)
            ->reelLike()
            ->with([
                'socialAccount',
                'analysis',
                'winnerInsight' => fn ($q) => $q->where('user_id', $user->id),
            ]);
    }

    /**
     * Build the paginated posts list for the backlog page's deferred payload.
     *
     * Deferred so the queue shell (filter tabs + counts) paints before the
     * heavier pagination + presenter + embed transforms run.
     *
     * @return LengthAwarePaginator<int, Post>
     */
    private function paginatedPosts(User $user, string $filter): LengthAwarePaginator
    {
        $query = match ($filter) {
            'failed' => $this->baseQuery($user)->analysisFailed(),
            'all' => $this->baseQuery($user)->analysisBacklog(),
            default => $this->baseQuery($user)->analysisQueue(),
        };

        $posts = $query
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

        return $posts;
    }
}
