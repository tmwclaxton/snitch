<?php

namespace App\Http\Controllers;

use App\Enums\Platform;
use App\Enums\PostType;
use App\Models\Post;
use App\Services\Analysis\AnalysisTermCatalogue;
use App\Services\Billing\PlanEntitlementService;
use App\Support\PlatformEmbed;
use App\Support\SafeMarkdown;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class FeedController extends Controller
{
    public function __construct(
        private AnalysisTermCatalogue $catalogue,
        private PlanEntitlementService $entitlements,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Post::class);

        $user = $request->user();
        $inQuotaIds = $this->entitlements->inQuotaTrackedAccountIds($user);

        $query = Post::query()
            ->where('user_id', $user->id)
            ->reelLike()
            ->with(['trackedAccount', 'analysis.terms', 'winnerInsight'])
            ->latest('posted_at');

        $this->entitlements->constrainPostsToInQuotaAccounts($query, $user);

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
            $accountId = $request->integer('account');
            $query->where('tracked_account_id', in_array($accountId, $inQuotaIds, true) ? $accountId : -1);
        }

        $posts = $query->paginate(24)->withQueryString();
        $posts->getCollection()->transform(function (Post $post): Post {
            $post->setAttribute(
                'embed',
                PlatformEmbed::resolve($post->platform, $post->url, compact: true),
            );

            if ($post->analysis !== null) {
                $post->analysis->setAttribute(
                    'term_labels',
                    $this->catalogue->frontendLabels($post->analysis->terms),
                );
            }

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
            'accounts' => $user->trackedAccounts()
                ->whereIn('id', $inQuotaIds === [] ? [-1] : $inQuotaIds)
                ->orderBy('handle')
                ->get(['id', 'handle', 'platform', 'display_name', 'avatar']),
        ]);
    }

    public function show(Request $request, Post $post): Response
    {
        $this->authorize('view', $post);

        $post->load(['trackedAccount', 'analysis.terms', 'winnerInsight']);
        $post->setAttribute(
            'embed',
            PlatformEmbed::resolve($post->platform, $post->url),
        );

        if ($post->analysis !== null) {
            $post->analysis->setAttribute(
                'how_to_copy_html',
                SafeMarkdown::toHtml($post->analysis->how_to_copy),
            );
            $post->analysis->setAttribute(
                'term_labels',
                $this->catalogue->frontendLabels($post->analysis->terms),
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
