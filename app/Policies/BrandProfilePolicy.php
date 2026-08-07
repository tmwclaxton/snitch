<?php

namespace App\Policies;

use App\Models\BrandProfile;
use App\Models\User;

class BrandProfilePolicy
{
    public function view(User $user, BrandProfile $brandProfile): bool
    {
        return $user->is($brandProfile->user);
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, BrandProfile $brandProfile): bool
    {
        return $user->is($brandProfile->user);
    }

    public function delete(User $user, BrandProfile $brandProfile): bool
    {
        return $user->is($brandProfile->user);
    }
}
