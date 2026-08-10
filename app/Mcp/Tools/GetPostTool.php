<?php

namespace App\Mcp\Tools;

use App\Enums\AnalysisStatus;
use App\Enums\PostType;
use App\Exceptions\InsufficientCreditsException;
use App\Mcp\Support\McpAuth;
use App\Models\Post;
use App\Services\Billing\ExploreBillingService;
use App\Support\PostAccountPresenter;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('get_post')]
#[Description('Get a post and its analysis if available. Tracked competitors are free; completed corpus reels whose author is not a tracked competitor charge 0.1p (idempotent per post).')]
class GetPostTool extends Tool
{
    public function handle(Request $request, ExploreBillingService $exploreBilling): Response
    {
        $user = McpAuth::user($request);
        if ($user instanceof Response) {
            return $user;
        }

        $data = $request->validate([
            'post_id' => ['required', 'integer'],
        ]);

        $post = Post::query()
            ->with(['analysis', 'socialAccount'])
            ->whereKey($data['post_id'])
            ->first();

        if ($post === null) {
            return Response::error('Post not found.');
        }

        $tracks = Post::query()->forUser($user)->whereKey($post->id)->exists();
        $isCompletedReel = in_array(
            $post->type?->value ?? (is_string($post->type) ? $post->type : null),
            PostType::analyzableValues(),
            true,
        ) && $post->analysis !== null
            && $post->analysis->status === AnalysisStatus::Completed;

        if (! $tracks && ! $isCompletedReel) {
            return Response::error('Post not found.');
        }

        try {
            $exploreBilling->chargeViewIfNeeded($user, $post);
        } catch (InsufficientCreditsException $exception) {
            return Response::error($exception->getMessage());
        }

        PostAccountPresenter::attachForUser([$post], $user);
        $post->makeHidden(['raw_payload']);

        return Response::json(['post' => $post]);
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'post_id' => $schema->integer()->required(),
        ];
    }
}
