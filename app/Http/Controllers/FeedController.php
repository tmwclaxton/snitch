<?php

namespace App\Http\Controllers;

use App\Enums\Platform;
use App\Enums\PostType;
use App\Exceptions\InsufficientCreditsException;
use App\Exceptions\PlatformSubscriptionRequiredException;
use App\Http\Controllers\Concerns\OmitsProductDataWhenPaywalled;
use App\Models\Post;
use App\Models\User;
use App\Services\Analysis\AnalysisTermCatalogue;
use App\Services\Billing\ExploreBillingService;
use App\Support\PlatformEmbed;
use App\Support\PostAccountPresenter;
use App\Support\SafeMarkdown;
use App\Support\UsableAnalysisCopy;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class FeedController extends Controller
{
    use OmitsProductDataWhenPaywalled;

    public function __construct(
        private AnalysisTermCatalogue $catalogue,
        private ExploreBillingService $exploreBilling,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Post::class);

        $user = $request->user();
        $filters = [
            'q' => $this->searchQuery($request),
            'platform' => $request->string('platform')->toString() ?: null,
            'type' => $request->string('type')->toString() ?: null,
        ];
        $platforms = collect(Platform::cases())->map(fn (Platform $p) => $p->value)->values();
        $types = collect(PostType::cases())->map(fn (PostType $t) => $t->value)->values();

        if ($this->productAccessBlocked($user)) {
            return Inertia::render('feed/Index', [
                'posts' => $this->emptyProductPaginator(),
                'filters' => $filters,
                'platforms' => $platforms,
                'types' => $types,
            ]);
        }

        return Inertia::render('feed/Index', [
            'posts' => Inertia::defer(fn () => $this->paginatedPosts($request, $user)),
            'filters' => $filters,
            'platforms' => $platforms,
            'types' => $types,
        ]);
    }

    /**
     * @return LengthAwarePaginator<int, Post>
     */
    private function paginatedPosts(Request $request, User $user): LengthAwarePaginator
    {
        $query = Post::query()
            ->forUser($user)
            ->visibleOnFeed()
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
            if (in_array($type, array_column(PostType::cases(), 'value'), true)) {
                $query->where('type', $type);
            }
        }

        $search = $this->searchQuery($request);

        if ($search !== null) {
            $this->constrainByLikeSearch($query, $search);
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
            UsableAnalysisCopy::applyToAnalysis($post->analysis);
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

    private function searchQuery(Request $request): ?string
    {
        $query = trim($request->string('q')->toString());

        if ($query === '') {
            return null;
        }

        return mb_substr($query, 0, 80);
    }

    /**
     * @param  Builder<Post>  $query
     */
    private function constrainByLikeSearch(Builder $query, string $queryText): void
    {
        $needle = ltrim($queryText, '@');
        $like = '%'.$needle.'%';

        $query->where(function (Builder $builder) use ($like): void {
            $builder
                ->where('caption', 'like', $like)
                ->orWhereHas('socialAccount', function (Builder $account) use ($like): void {
                    $account
                        ->where('handle', 'like', $like)
                        ->orWhere('display_name', 'like', $like);
                })
                ->orWhereHas('analysis', function (Builder $analysis) use ($like): void {
                    $analysis
                        ->where('hook', 'like', $like)
                        ->orWhere('concept', 'like', $like)
                        ->orWhere('idea', 'like', $like)
                        ->orWhere('visual_summary', 'like', $like)
                        ->orWhere('topics', 'like', $like)
                        ->orWhere('custom_tags', 'like', $like);
                });
        });
    }
}
