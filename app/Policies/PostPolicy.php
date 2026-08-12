<?php

namespace App\Policies;

use App\Enums\PostType;
use App\Models\Post;
use App\Models\TrackedAccount;
use App\Models\User;

class PostPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Post $post): bool
    {
        if ($this->userTracks($user, $post)) {
            return true;
        }

        // Shared corpus: authenticated users may open any reel-like post (Explore catalog).
        return $this->isReelLike($post);
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Post $post): bool
    {
        return $this->userTracks($user, $post);
    }

    public function delete(User $user, Post $post): bool
    {
        return $this->userTracks($user, $post);
    }

    private function userTracks(User $user, Post $post): bool
    {
        if ($post->social_account_id === null) {
            return false;
        }

        return TrackedAccount::query()
            ->where('user_id', $user->id)
            ->where('social_account_id', $post->social_account_id)
            ->exists();
    }

    private function isReelLike(Post $post): bool
    {
        $type = $post->type;

        if ($type instanceof PostType) {
            return $type->isReelLike();
        }

        if (is_string($type)) {
            return in_array($type, PostType::analyzableValues(), true);
        }

        return false;
    }
}
