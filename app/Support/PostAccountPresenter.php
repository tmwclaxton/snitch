<?php

namespace App\Support;

use App\Enums\Platform;
use App\Models\Post;
use App\Models\TrackedAccount;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Throwable;
use ValueError;

class PostAccountPresenter
{
    /**
     * Attach a viewer-scoped `tracked_account` payload for Inertia/MCP.
     * Uses the viewer's membership when present; otherwise handle-only from the global social account.
     * Always normalizes `posts.platform` to a lowercase network slug string for the frontend.
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
            self::normalizePlatform($post);

            $membership = $memberships->get($post->social_account_id);
            $platform = self::platformValue($post);

            if ($membership instanceof TrackedAccount) {
                $post->setAttribute('tracked_account', [
                    'id' => $membership->id,
                    'handle' => $membership->handle,
                    'display_name' => $membership->display_name,
                    'platform' => $membership->platform instanceof Platform
                        ? $membership->platform->value
                        : ($platform ?? (string) $membership->platform),
                    'avatar' => $membership->avatar,
                    'url' => $membership->url,
                ]);

                continue;
            }

            $social = $post->socialAccount;
            $post->setAttribute('tracked_account', $social === null ? null : [
                'handle' => $social->handle,
                'display_name' => $social->display_name,
                'platform' => $social->platform instanceof Platform
                    ? $social->platform->value
                    : ($platform ?? (string) $social->platform),
                'avatar' => $social->avatar,
                'url' => $social->url,
            ]);
        }
    }

    /**
     * Ensure the post carries a real network slug (`instagram`, `tiktok`, …)
     * for Inertia cards. Prefer the post column, then the social account.
     */
    public static function normalizePlatform(Post $post): void
    {
        $value = self::platformValue($post);

        if ($value === null) {
            return;
        }

        $post->setAttribute('platform', $value);
    }

    public static function platformValue(Post $post): ?string
    {
        foreach ([self::rawPlatform($post), self::castPlatform($post)] as $candidate) {
            if ($candidate !== null) {
                return $candidate;
            }
        }

        $post->loadMissing('socialAccount');
        $social = $post->socialAccount;

        if ($social === null) {
            return null;
        }

        $socialRaw = $social->getRawOriginal('platform');

        if (is_string($socialRaw)) {
            $normalized = strtolower(trim($socialRaw));

            if ($normalized !== '' && Platform::tryFrom($normalized) !== null) {
                return $normalized;
            }
        }

        if ($social->platform instanceof Platform) {
            return $social->platform->value;
        }

        return null;
    }

    private static function rawPlatform(Post $post): ?string
    {
        $raw = $post->getRawOriginal('platform');

        if (! is_string($raw)) {
            return null;
        }

        $normalized = strtolower(trim($raw));

        if ($normalized === '' || Platform::tryFrom($normalized) === null) {
            return null;
        }

        return $normalized;
    }

    private static function castPlatform(Post $post): ?string
    {
        try {
            $cast = $post->platform;
        } catch (ValueError|Throwable) {
            return null;
        }

        if ($cast instanceof Platform) {
            return $cast->value;
        }

        if (is_string($cast)) {
            $normalized = strtolower(trim($cast));

            return $normalized !== '' && Platform::tryFrom($normalized) !== null
                ? $normalized
                : null;
        }

        return null;
    }
}
