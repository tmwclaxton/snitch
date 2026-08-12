<?php

use App\Http\Controllers\ClaimController;
use App\Services\Billing\AccountClaimService;
use App\Services\Billing\UsageBillingService;
use App\Services\Referrals\ReferralAttribution;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Route;
use Laravel\WorkOS\Http\Requests\AuthKitAuthenticationRequest;
use Laravel\WorkOS\Http\Requests\AuthKitLoginRequest;
use Laravel\WorkOS\Http\Requests\AuthKitLogoutRequest;
use Laravel\WorkOS\User as WorkOsUser;

Route::middleware(['guest'])->group(function () {
    Route::get('login', fn (AuthKitLoginRequest $request) => $request->redirect())->name('login');

    Route::get('claim/{token}', [ClaimController::class, 'show'])->name('claim.show');
    Route::post('claim/{token}', [ClaimController::class, 'start'])->name('claim.start');

    Route::get('authenticate', function (AuthKitAuthenticationRequest $request) {
        $code = $request->query('code');

        if (! is_string($code) || $code === '') {
            return redirect()->route('login');
        }

        /** @var AccountClaimService $claims */
        $claims = app(AccountClaimService::class);

        $request->authenticate(
            findUsing: function (WorkOsUser $workOsUser) use ($claims): ?Authenticatable {
                $userModel = config('auth.providers.users.model');

                $byWorkOs = $userModel::query()->where('workos_id', $workOsUser->id)->first();

                if ($byWorkOs !== null) {
                    return $byWorkOs;
                }

                $claimToken = session('snitch_claim_token');

                if (is_string($claimToken) && $claimToken !== '') {
                    $unclaimed = $claims->findUnclaimedByToken($claimToken);

                    if ($unclaimed !== null) {
                        session()->forget('snitch_claim_token');

                        return $claims->claim($unclaimed, $workOsUser);
                    }
                }

                $byEmail = $claims->findUnclaimedByEmail($workOsUser->email);

                if ($byEmail !== null) {
                    return $claims->claim($byEmail, $workOsUser);
                }

                return null;
            },
            createUsing: function (WorkOsUser $workOsUser) use ($request): Authenticatable {
                $userModel = config('auth.providers.users.model');

                $user = $userModel::query()->create([
                    'name' => trim(($workOsUser->firstName ?? '').' '.($workOsUser->lastName ?? '')) ?: 'Snitch user',
                    'email' => $workOsUser->email,
                    'email_verified_at' => now(),
                    'workos_id' => $workOsUser->id,
                    'avatar' => $workOsUser->avatar ?? '',
                    'created_via' => 'web',
                    'claim_token' => null,
                    'claimed_at' => now(),
                ]);

                app(UsageBillingService::class)->creditClaimBonus($user);
                app(ReferralAttribution::class)->bindToUser($user, $request);

                return $user;
            },
        );

        return redirect()->intended(route('dashboard'));
    });
});

Route::post('logout', fn (AuthKitLogoutRequest $request) => $request->logout())
    ->middleware(['auth'])->name('logout');
