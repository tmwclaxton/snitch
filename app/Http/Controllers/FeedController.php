<?php

namespace App\Http\Controllers;

use App\Enums\Platform;
use App\Enums\PostType;
use App\Models\Post;
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
            ->with(['trackedAccount', 'analysis'])
            ->latest('posted_at');

        if ($request->filled('platform')) {
            $query->where('platform', $request->string('platform')->toString());
        }

        if ($request->filled('type')) {
            $query->where('type', $request->string('type')->toString());
        }

        if ($request->filled('account')) {
            $query->where('tracked_account_id', $request->integer('account'));
        }

        return Inertia::render('feed/Index', [
            'posts' => $query->paginate(24)->withQueryString(),
            'filters' => [
                'platform' => $request->string('platform')->toString() ?: null,
                'type' => $request->string('type')->toString() ?: null,
                'account' => $request->integer('account') ?: null,
            ],
            'platforms' => collect(Platform::cases())->map(fn (Platform $p) => $p->value)->values(),
            'types' => collect(PostType::cases())->map(fn (PostType $t) => $t->value)->values(),
            'accounts' => $user->trackedAccounts()->orderBy('handle')->get(['id', 'handle', 'platform', 'display_name', 'avatar']),
        ]);
    }

    public function show(Request $request, Post $post): Response
    {
        $this->authorize('view', $post);

        $post->load(['trackedAccount', 'analysis', 'winnerInsight']);

        return Inertia::render('feed/Show', [
            'post' => $post,
        ]);
    }
}
