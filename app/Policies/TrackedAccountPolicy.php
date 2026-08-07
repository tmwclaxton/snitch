<?php

namespace App\Policies;

use App\Models\TrackedAccount;
use App\Models\User;

class TrackedAccountPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, TrackedAccount $trackedAccount): bool
    {
        return $user->is($trackedAccount->user);
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, TrackedAccount $trackedAccount): bool
    {
        return $user->is($trackedAccount->user);
    }

    public function delete(User $user, TrackedAccount $trackedAccount): bool
    {
        return $user->is($trackedAccount->user);
    }
}
