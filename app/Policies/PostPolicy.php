<?php

namespace App\Policies;

use App\Models\Post;
use App\Models\User;
use App\Services\Billing\PlanEntitlementService;

class PostPolicy
{
    public function __construct(private PlanEntitlementService $entitlements) {}

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Post $post): bool
    {
        if (! $user->is($post->user)) {
            return false;
        }

        $accountId = $post->tracked_account_id;

        if ($accountId === null) {
            return true;
        }

        return $this->entitlements->isTrackedAccountInQuota($user, (int) $accountId);
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Post $post): bool
    {
        return $user->is($post->user);
    }

    public function delete(User $user, Post $post): bool
    {
        return $user->is($post->user);
    }
}
