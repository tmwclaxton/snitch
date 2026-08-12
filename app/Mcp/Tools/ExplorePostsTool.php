<?php

namespace App\Mcp\Tools;

use App\Enums\AnalysisStatus;
use App\Enums\AnalysisTermDimension;
use App\Exceptions\InsufficientCreditsException;
use App\Exceptions\PlatformSubscriptionRequiredException;
use App\Mcp\Support\McpAppUrls;
use App\Mcp\Support\McpAuth;
use App\Models\AnalysisTerm;
use App\Models\Post;
use App\Models\User;
use App\Services\Analysis\AnalysisEmbeddingService;
use App\Services\Billing\ExploreBillingService;
use App\Support\PostAccountPresenter;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Collection;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('explore_posts')]
#[Description('Browse analysed reels across the shared platform corpus. Optional q uses semantic search (LIKE fallback); optional topics/hook_types catalogue slugs; optional post_id returns detail. Search charges up to 0.5p proportional to result count (0p when empty); detail may charge 0.1p when the author is not a tracked snitch. Posts include snitch_url.')]
class ExplorePostsTool extends Tool
{
    public function handle(
        Request $request,
        ExploreBillingService $exploreBilling,
        AnalysisEmbeddingService $embeddings,
    ): Response {
        $user = McpAuth::user($request);
        if ($user instanceof Response) {
            return $user;
        }

        if ($blocked = McpAuth::requireProductAccess($user)) {
            return $blocked;
        }

        $data = $request->validate([
            'platform' => ['nullable', 'string', 'in:instagram,tiktok,youtube,facebook,linkedin'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
            'include_terms' => ['nullable', 'boolean'],
            'q' => ['nullable', 'string', 'max:200'],
            'topics' => ['nullable', 'array', 'max:20'],
            'topics.*' => ['string', 'max:80'],
            'hook_types' => ['nullable', 'array', 'max:20'],
            'hook_types.*' => ['string', 'max:80'],
            'post_id' => ['nullable', 'integer'],
        ]);

        if (! empty($data['post_id'])) {
            return $this->detail($user, (int) $data['post_id'], $exploreBilling);
        }

        $limit = (int) ($data['limit'] ?? 20);
        $queryText = isset($data['q']) ? trim((string) $data['q']) : '';
        $topics = $this->slugList($data['topics'] ?? null);
        $hookTypes = $this->slugList($data['hook_types'] ?? null);
        $includeTerms = (bool) ($data['include_terms'] ?? false);

        $base = Post::query()
            ->reelLike()
            ->whereHas('analysis', fn ($a) => $a->where('status', AnalysisStatus::Completed));

        if (! empty($data['platform'])) {
            $base->where('platform', $data['platform']);
        }

        if ($topics !== []) {
            $this->constrainByTermSlugs($base, AnalysisTermDimension::Topic, $topics);
        }

        if ($hookTypes !== []) {
            $this->constrainByTermSlugs($base, AnalysisTermDimension::HookType, $hookTypes);
        }

        $posts = collect();
        $hint = null;

        if ($queryText !== '') {
            $posts = $this->searchPosts(clone $base, $queryText, $limit, $embeddings);
            $resultCount = $posts->count();

            try {
                $exploreBilling->chargeSearch($user, $queryText, 'q', $resultCount);
            } catch (PlatformSubscriptionRequiredException|InsufficientCreditsException $exception) {
                return Response::error($exception->getMessage());
            }

            if ($resultCount === 0) {
                $hint = $this->emptySearchHint($queryText, $topics, $hookTypes);
            }
        } else {
            $posts = (clone $base)
                ->with(['socialAccount:id,handle,platform', 'analysis:id,post_id,status'])
                ->latest('posted_at')
                ->limit($limit)
                ->get(['id', 'platform', 'url', 'caption', 'posted_at', 'social_account_id', 'type']);
        }

        PostAccountPresenter::attachForUser($posts, $user);
        $posts->each(fn (Post $post) => $post->makeHidden(['raw_payload']));
        McpAppUrls::attachSnitchUrls($posts);

        $payload = [
            'posts' => $posts->values(),
            'explore_url' => McpAppUrls::explore(),
        ];

        if ($hint !== null) {
            $payload['hint'] = $hint;
        }

        if ($includeTerms || ($queryText !== '' && $posts->isEmpty())) {
            $payload['terms'] = $this->matchingTerms($queryText, $topics, $hookTypes);
        }

        return Response::json($payload);
    }

    /**
     * @return Collection<int, Post>
     */
    private function searchPosts(
        Builder $base,
        string $queryText,
        int $limit,
        AnalysisEmbeddingService $embeddings,
    ): Collection {
        $queryVector = $embeddings->embedQuery($queryText);

        if ($queryVector !== null) {
            $maxCandidates = max(1, (int) config('snitch.embeddings.max_candidates', 500));
            $candidates = (clone $base)
                ->with(['socialAccount:id,handle,platform', 'analysis'])
                ->latest('posted_at')
                ->limit($maxCandidates)
                ->get(['id', 'platform', 'url', 'caption', 'posted_at', 'social_account_id', 'type']);

            $scored = $embeddings->scorePosts($candidates, $queryVector);

            if ($scored !== []) {
                arsort($scored);
                $orderedIds = array_slice(array_map('intval', array_keys($scored)), 0, $limit);
                $byId = $candidates->keyBy('id');

                return collect($orderedIds)
                    ->map(fn (int $id): ?Post => $byId->get($id))
                    ->filter()
                    ->values();
            }
        }

        $like = '%'.$queryText.'%';
        $query = clone $base;
        $query->where(function (Builder $builder) use ($like): void {
            $builder
                ->where('caption', 'like', $like)
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

        return $query
            ->with(['socialAccount:id,handle,platform', 'analysis:id,post_id,status'])
            ->latest('posted_at')
            ->limit($limit)
            ->get(['id', 'platform', 'url', 'caption', 'posted_at', 'social_account_id', 'type']);
    }

    /**
     * @param  list<string>  $topics
     * @param  list<string>  $hookTypes
     * @return array{message: string, suggested_topics: list<string>, suggested_hook_types: list<string>}
     */
    private function emptySearchHint(string $queryText, array $topics, array $hookTypes): array
    {
        $suggested = $this->matchingTerms($queryText, $topics, $hookTypes);

        return [
            'message' => 'No posts matched that query. Try catalogue topic/hook_type slugs (e.g. topics=["ai_tools","seo"]) or a shorter phrase.',
            'suggested_topics' => collect($suggested)
                ->where('dimension', AnalysisTermDimension::Topic->value)
                ->pluck('slug')
                ->take(8)
                ->values()
                ->all(),
            'suggested_hook_types' => collect($suggested)
                ->where('dimension', AnalysisTermDimension::HookType->value)
                ->pluck('slug')
                ->take(8)
                ->values()
                ->all(),
        ];
    }

    /**
     * @param  list<string>  $topics
     * @param  list<string>  $hookTypes
     * @return list<array{id: int, dimension: string, slug: string, label: string}>
     */
    private function matchingTerms(string $queryText, array $topics, array $hookTypes): array
    {
        $slugs = array_values(array_unique([...$topics, ...$hookTypes]));
        $needle = mb_strtolower(trim($queryText));

        $query = AnalysisTerm::query()->orderBy('dimension')->orderBy('slug');

        if ($slugs !== [] || $needle !== '') {
            $query->where(function (Builder $builder) use ($slugs, $needle): void {
                if ($slugs !== []) {
                    $builder->whereIn('slug', $slugs);
                }

                if ($needle !== '') {
                    $builder->orWhere('slug', 'like', '%'.$needle.'%')
                        ->orWhere('label', 'like', '%'.$needle.'%');

                    foreach (preg_split('/\s+/', $needle) ?: [] as $token) {
                        $token = trim($token);
                        if (strlen($token) < 3) {
                            continue;
                        }
                        $builder->orWhere('slug', 'like', '%'.$token.'%')
                            ->orWhere('label', 'like', '%'.$token.'%');
                    }
                }
            });
        } else {
            return [];
        }

        return $query
            ->limit(24)
            ->get(['id', 'dimension', 'slug', 'label'])
            ->map(fn (AnalysisTerm $term): array => [
                'id' => $term->id,
                'dimension' => $term->dimension instanceof AnalysisTermDimension
                    ? $term->dimension->value
                    : (string) $term->dimension,
                'slug' => $term->slug,
                'label' => $term->label,
            ])
            ->all();
    }

    /**
     * @param  Builder<Post>  $query
     * @param  list<string>  $slugs
     */
    private function constrainByTermSlugs(Builder $query, AnalysisTermDimension $dimension, array $slugs): void
    {
        $query->whereHas('analysis.terms', function (Builder $terms) use ($dimension, $slugs): void {
            $terms
                ->where('dimension', $dimension->value)
                ->whereIn('slug', $slugs);
        });
    }

    /**
     * @return list<string>
     */
    private function slugList(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn (mixed $value): string => trim((string) $value),
            $raw,
        ), static fn (string $slug): bool => $slug !== ''));
    }

    private function detail(User $user, int $postId, ExploreBillingService $exploreBilling): Response
    {
        $post = Post::query()
            ->with(['socialAccount:id,handle,platform,external_id', 'analysis'])
            ->whereKey($postId)
            ->first();

        if ($post === null || ! $user->can('view', $post)) {
            return Response::error('Post not found.');
        }

        try {
            $exploreBilling->chargeViewIfNeeded($user, $post);
        } catch (PlatformSubscriptionRequiredException|InsufficientCreditsException $exception) {
            return Response::error($exception->getMessage());
        }

        PostAccountPresenter::attachForUser([$post], $user);
        $post->makeHidden(['raw_payload']);
        McpAppUrls::attachSnitchUrls([$post]);

        return Response::json(['post' => $post]);
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'platform' => $schema->string()->nullable(),
            'limit' => $schema->integer()->nullable(),
            'include_terms' => $schema->boolean()->nullable(),
            'q' => $schema->string()->nullable(),
            'topics' => $schema->array()->items($schema->string())->nullable(),
            'hook_types' => $schema->array()->items($schema->string())->nullable(),
            'post_id' => $schema->integer()->nullable(),
        ];
    }
}
