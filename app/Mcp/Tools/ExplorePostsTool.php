<?php

namespace App\Mcp\Tools;

use App\Enums\AnalysisStatus;
use App\Enums\PostType;
use App\Exceptions\InsufficientCreditsException;
use App\Exceptions\PlatformSubscriptionRequiredException;
use App\Mcp\Support\McpAuth;
use App\Models\AnalysisTerm;
use App\Models\Post;
use App\Models\User;
use App\Services\Billing\ExploreBillingService;
use App\Support\PostAccountPresenter;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('explore_posts')]
#[Description('Browse analysed reels across the shared platform corpus. Optional q charges up to 0.5p proportional to result count (0p when empty); optional post_id returns detail and may charge 0.1p when the author is not a tracked snitch.')]
class ExplorePostsTool extends Tool
{
    public function handle(Request $request, ExploreBillingService $exploreBilling): Response
    {
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
            'post_id' => ['nullable', 'integer'],
        ]);

        if (! empty($data['post_id'])) {
            return $this->detail($user, (int) $data['post_id'], $exploreBilling);
        }

        $queryText = isset($data['q']) ? trim((string) $data['q']) : '';
        if ($queryText !== '') {
            $query = Post::query()
                ->reelLike()
                ->whereHas('analysis', fn ($a) => $a->where('status', AnalysisStatus::Completed))
                ->latest('posted_at')
                ->limit((int) ($data['limit'] ?? 20));

            if (! empty($data['platform'])) {
                $query->where('platform', $data['platform']);
            }

            $like = '%'.$queryText.'%';
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

            $resultCount = (clone $query)->count();

            try {
                $exploreBilling->chargeSearch($user, $queryText, 'q', $resultCount);
            } catch (PlatformSubscriptionRequiredException|InsufficientCreditsException $exception) {
                return Response::error($exception->getMessage());
            }

            $posts = $query
                ->with(['socialAccount:id,handle,platform', 'analysis:id,post_id,status'])
                ->get(['id', 'platform', 'url', 'caption', 'posted_at', 'social_account_id', 'type']);
        } else {
            $query = Post::query()
                ->reelLike()
                ->whereHas('analysis', fn ($a) => $a->where('status', AnalysisStatus::Completed))
                ->with(['socialAccount:id,handle,platform', 'analysis:id,post_id,status'])
                ->latest('posted_at')
                ->limit((int) ($data['limit'] ?? 20));

            if (! empty($data['platform'])) {
                $query->where('platform', $data['platform']);
            }

            $posts = $query->get(['id', 'platform', 'url', 'caption', 'posted_at', 'social_account_id', 'type']);
        }

        PostAccountPresenter::attachForUser($posts, $user);
        $posts->each(fn (Post $post) => $post->makeHidden(['raw_payload']));

        $payload = [
            'posts' => $posts,
        ];

        if ($data['include_terms'] ?? false) {
            $payload['terms'] = AnalysisTerm::query()
                ->orderBy('dimension')
                ->orderBy('slug')
                ->limit(100)
                ->get(['id', 'dimension', 'slug', 'label']);
        }

        return Response::json($payload);
    }

    private function detail(User $user, int $postId, ExploreBillingService $exploreBilling): Response
    {
        $post = Post::query()
            ->with(['socialAccount:id,handle,platform,external_id', 'analysis'])
            ->whereKey($postId)
            ->first();

        if ($post === null || ! $this->isCompletedReel($post)) {
            return Response::error('Post not found.');
        }

        try {
            $exploreBilling->chargeViewIfNeeded($user, $post);
        } catch (PlatformSubscriptionRequiredException|InsufficientCreditsException $exception) {
            return Response::error($exception->getMessage());
        }

        PostAccountPresenter::attachForUser([$post], $user);
        $post->makeHidden(['raw_payload']);

        return Response::json(['post' => $post]);
    }

    private function isCompletedReel(Post $post): bool
    {
        if (! in_array(
            $post->type?->value ?? (is_string($post->type) ? $post->type : null),
            PostType::analyzableValues(),
            true,
        )) {
            return false;
        }

        $analysis = $post->analysis;

        return $analysis !== null && $analysis->status === AnalysisStatus::Completed;
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'platform' => $schema->string()->nullable(),
            'limit' => $schema->integer()->nullable(),
            'include_terms' => $schema->boolean()->nullable(),
            'q' => $schema->string()->nullable(),
            'post_id' => $schema->integer()->nullable(),
        ];
    }
}
