<?php

namespace App\Policies;

use App\Models\TrackedAccount;
use App\Models\User;
use App\Services\Billing\PlanEntitlementService;

class TrackedAccountPolicy
{
    public function __construct(private PlanEntitlementService $entitlements) {}

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
        return $this->entitlements->canAddCompetitors($user, 1);
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
