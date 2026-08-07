<?php

namespace App\Policies;

use App\Models\User;
use App\Models\WinnerRule;

class WinnerRulePolicy
{
    public function view(User $user, WinnerRule $winnerRule): bool
    {
        return $user->is($winnerRule->user);
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, WinnerRule $winnerRule): bool
    {
        return $user->is($winnerRule->user);
    }

    public function delete(User $user, WinnerRule $winnerRule): bool
    {
        return $user->is($winnerRule->user);
    }
}
