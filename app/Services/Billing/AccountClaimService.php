<?php

namespace App\Services\Billing;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\WorkOS\User as WorkOsUser;
use RuntimeException;

class AccountClaimService
{
    public function __construct(private UsageBillingService $usage) {}

    /**
     * @return array{user: User, plain_text_token: string, claim_url: string}
     */
    public function createAgentAccount(?string $name = null, ?string $email = null): array
    {
        $claimToken = Str::random(48);
        $email ??= 'agent+'.Str::lower(Str::random(16)).'@users.snitchsocial.net';

        $user = User::query()->create([
            'name' => $name ?: 'Agent account',
            'email' => $email,
            'email_verified_at' => null,
            'workos_id' => null,
            'avatar' => '',
            'created_via' => 'mcp',
            'claim_token' => $claimToken,
            'claimed_at' => null,
        ]);

        $token = $user->createToken('mcp')->plainTextToken;

        return [
            'user' => $user,
            'plain_text_token' => $token,
            'claim_url' => url('/claim/'.$claimToken),
        ];
    }

    public function claim(User $user, WorkOsUser $workOsUser): User
    {
        if ($user->isClaimed()) {
            throw new RuntimeException('This account has already been claimed.');
        }

        $conflict = User::query()
            ->where('workos_id', $workOsUser->id)
            ->where('id', '!=', $user->id)
            ->exists();

        if ($conflict) {
            throw new RuntimeException('This WorkOS identity is already linked to another Snitch account.');
        }

        return DB::transaction(function () use ($user, $workOsUser): User {
            $user->forceFill([
                'workos_id' => $workOsUser->id,
                'email' => $workOsUser->email,
                'name' => trim(($workOsUser->firstName ?? '').' '.($workOsUser->lastName ?? '')) ?: $user->name,
                'avatar' => $workOsUser->avatar ?? $user->avatar,
                'email_verified_at' => now(),
                'claimed_at' => now(),
                'claim_token' => null,
            ])->save();

            $this->usage->creditClaimBonus($user);

            return $user->fresh();
        });
    }

    public function claimWebSignup(User $user): User
    {
        if ($user->claimed_at !== null) {
            return $user;
        }

        $user->forceFill([
            'created_via' => $user->created_via ?: 'web',
            'claimed_at' => now(),
            'claim_token' => null,
        ])->save();

        $this->usage->creditClaimBonus($user);

        return $user->fresh() ?? $user;
    }

    public function findUnclaimedByToken(string $token): ?User
    {
        return User::query()
            ->where('claim_token', $token)
            ->whereNull('claimed_at')
            ->first();
    }

    public function findUnclaimedByEmail(string $email): ?User
    {
        return User::query()
            ->where('email', $email)
            ->whereNull('claimed_at')
            ->whereNull('workos_id')
            ->first();
    }
}
