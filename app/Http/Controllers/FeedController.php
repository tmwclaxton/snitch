<?php

namespace App\Http\Controllers;

use App\Enums\Platform;
use App\Enums\PostType;
use App\Exceptions\InsufficientCreditsException;
use App\Exceptions\PlatformSubscriptionRequiredException;
use App\Http\Controllers\Concerns\OmitsProductDataWhenPaywalled;
use App\Models\Post;
use App\Models\TrackedAccount;
use App\Models\User;
use App\Services\Analysis\AnalysisTermCatalogue;
use App\Services\Billing\ExploreBillingService;
use App\Services\Billing\PlanEntitlementService;
use App\Support\PlatformEmbed;
use App\Support\PostAccountPresenter;
use App\Support\SafeMarkdown;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class FeedController extends Controller
{
    use OmitsProductDataWhenPaywalled;

    public function __construct(
        private AnalysisTermCatalogue $catalogue,
        private PlanEntitlementService $entitlements,
        private ExploreBillingService $exploreBilling,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Post::class);

        $user = $request->user();
        $filters = [
            'platform' => $request->string('platform')->toString() ?: null,
            'type' => $request->string('type')->toString() ?: null,
            'account' => $request->integer('account') ?: null,
        ];
        $platforms = collect(Platform::cases())->map(fn (Platform $p) => $p->value)->values();
        $types = collect(PostType::analyzable())->map(fn (PostType $t) => $t->value)->values();

        if ($this->productAccessBlocked($user)) {
            return Inertia::render('feed/Index', [
                'posts' => $this->emptyProductPaginator(),
                'filters' => $filters,
                'platforms' => $platforms,
                'types' => $types,
                'accounts' => [],
            ]);
        }

        $inQuotaIds = $this->entitlements->inQuotaTrackedAccountIds($user);

        return Inertia::render('feed/Index', [
            'posts' => Inertia::defer(fn () => $this->paginatedPosts($request, $user, $inQuotaIds)),
            'filters' => $filters,
            'platforms' => $platforms,
            'types' => $types,
            'accounts' => $user->trackedAccounts()
                ->whereIn('id', $inQuotaIds === [] ? [-1] : $inQuotaIds)
                ->orderBy('handle')
                ->get(['id', 'handle', 'platform', 'display_name', 'avatar']),
        ]);
    }

    /**
     * @param  list<int>  $inQuotaIds
     * @return LengthAwarePaginator<int, Post>
     */
    private function paginatedPosts(Request $request, User $user, array $inQuotaIds): LengthAwarePaginator
    {
        $query = Post::query()
            ->forUser($user)
            ->reelLike()
            ->with([
                'socialAccount',
                'analysis.terms',
                'winnerInsight' => fn ($q) => $q->where('user_id', $user->id),
            ])
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
            $accountId = $request->integer('account');
            $socialId = in_array($accountId, $inQuotaIds, true)
                ? TrackedAccount::query()->whereKey($accountId)->value('social_account_id')
                : null;
            $query->where('social_account_id', $socialId ?? -1);
        }

        $posts = $query->paginate(24)->withQueryString();
        PostAccountPresenter::attachForUser($posts->getCollection(), $user);
        $posts->getCollection()->transform(function (Post $post): Post {
            $post->makeHidden(['raw_payload']);
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

        return $posts;
    }

    public function show(Request $request, Post $post): Response|RedirectResponse
    {
        $this->authorize('view', $post);

        $user = $request->user();

        if ($this->productAccessBlocked($user)) {
            return redirect()->route('feed.index');
        }

        $post->load([
            'socialAccount',
            'analysis.terms',
            'winnerInsight' => fn ($q) => $q->where('user_id', $user->id),
        ]);

        try {
            $this->exploreBilling->chargeViewIfNeeded($user, $post);
        } catch (PlatformSubscriptionRequiredException|InsufficientCreditsException $exception) {
            Inertia::flash('toast', [
                'type' => 'error',
                'message' => $exception->getMessage(),
            ]);

            return redirect()->route('billing.edit');
        }

        PostAccountPresenter::attachForUser([$post], $user);
        $post->makeHidden(['raw_payload']);
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
