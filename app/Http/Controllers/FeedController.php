<?php

namespace App\Http\Controllers;

use App\Enums\Platform;
use App\Enums\PostType;
use App\Models\Post;
use App\Support\PlatformEmbed;
use App\Support\SafeMarkdown;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class FeedController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Post::class);

        $user = $request->user();

        $query = Post::query()
            ->where('user_id', $user->id)
            ->reelLike()
            ->with(['trackedAccount', 'analysis', 'winnerInsight'])
            ->latest('posted_at');

        if ($request->filled('platform')) {
            $query->where('platform', $request->string('platform')->toString());
        }

        if ($request->filled('type')) {
            $type = $request->string('type')->toString();
            if (in_array($type, PostType::analyzableValues(), true)) {
                $query->where('type', $type);
            }
        }

        if ($request->filled('account')) {
            $query->where('tracked_account_id', $request->integer('account'));
        }

        $posts = $query->paginate(24)->withQueryString();
        $posts->getCollection()->transform(function (Post $post): Post {
            $post->setAttribute(
                'embed',
                PlatformEmbed::resolve($post->platform, $post->url, compact: true),
            );

            return $post;
        });

        return Inertia::render('feed/Index', [
            'posts' => $posts,
            'filters' => [
                'platform' => $request->string('platform')->toString() ?: null,
                'type' => $request->string('type')->toString() ?: null,
                'account' => $request->integer('account') ?: null,
            ],
            'platforms' => collect(Platform::cases())->map(fn (Platform $p) => $p->value)->values(),
            'types' => collect(PostType::analyzable())->map(fn (PostType $t) => $t->value)->values(),
            'accounts' => $user->trackedAccounts()->orderBy('handle')->get(['id', 'handle', 'platform', 'display_name', 'avatar']),
        ]);
    }

    public function show(Request $request, Post $post): Response
    {
        $this->authorize('view', $post);

        $post->load(['trackedAccount', 'analysis', 'winnerInsight']);
        $post->setAttribute(
            'embed',
            PlatformEmbed::resolve($post->platform, $post->url),
        );

        if ($post->analysis !== null) {
            $post->analysis->setAttribute(
                'how_to_copy_html',
                SafeMarkdown::toHtml($post->analysis->how_to_copy),
            );
        }

        if ($post->winnerInsight !== null) {
            $post->winnerInsight->setAttribute(
                'how_to_copy_html',
                SafeMarkdown::toHtml($post->winnerInsight->how_to_copy),
            );
        }

        return Inertia::render('feed/Show', [
            'post' => $post,
        ]);
    }
}
