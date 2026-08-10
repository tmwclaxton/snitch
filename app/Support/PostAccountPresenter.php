<?php

namespace App\Support;

use App\Models\Post;
use App\Models\TrackedAccount;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

class PostAccountPresenter
{
    /**
     * Attach a viewer-scoped `tracked_account` payload for Inertia/MCP.
     * Uses the viewer's membership when present; otherwise handle-only from the global social account.
     *
     * @param  EloquentCollection<int, Post>|iterable<Post>  $posts
     */
    public static function attachForUser(iterable $posts, User $user): void
    {
        $posts = $posts instanceof EloquentCollection
            ? $posts->filter(fn (mixed $post): bool => $post instanceof Post)->values()
            : EloquentCollection::make($posts)->filter(fn (mixed $post): bool => $post instanceof Post)->values();

        if ($posts->isEmpty()) {
            return;
        }

        $socialIds = $posts->pluck('social_account_id')->filter()->unique()->values()->all();

        $memberships = TrackedAccount::query()
            ->where('user_id', $user->id)
            ->whereIn('social_account_id', $socialIds === [] ? [-1] : $socialIds)
            ->get()
            ->keyBy('social_account_id');

        $posts->loadMissing('socialAccount');

        foreach ($posts as $post) {
            $membership = $memberships->get($post->social_account_id);

            if ($membership instanceof TrackedAccount) {
                $post->setAttribute('tracked_account', [
                    'id' => $membership->id,
                    'handle' => $membership->handle,
                    'display_name' => $membership->display_name,
                    'platform' => $membership->platform?->value ?? (string) $membership->platform,
                    'avatar' => $membership->avatar,
                ]);

                continue;
            }

            $social = $post->socialAccount;
            $post->setAttribute('tracked_account', $social === null ? null : [
                'handle' => $social->handle,
                'display_name' => $social->display_name,
                'platform' => $social->platform?->value ?? (string) $social->platform,
                'avatar' => $social->avatar,
            ]);
        }
    }
}
