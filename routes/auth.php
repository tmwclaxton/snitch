<?php

use App\Services\Billing\PlanEntitlementService;
use Illuminate\Support\Facades\Route;
use Laravel\WorkOS\Http\Requests\AuthKitAuthenticationRequest;
use Laravel\WorkOS\Http\Requests\AuthKitLoginRequest;
use Laravel\WorkOS\Http\Requests\AuthKitLogoutRequest;

Route::middleware(['guest'])->group(function () {
    Route::get('login', fn (AuthKitLoginRequest $request) => $request->redirect())->name('login');

    Route::get('authenticate', function (AuthKitAuthenticationRequest $request) {
        $code = $request->query('code');

        if (! is_string($code) || $code === '') {
            return redirect()->route('login');
        }

        $request->authenticate();

        $user = $request->user();
        if ($user !== null) {
            app(PlanEntitlementService::class)->ensureTrialStarted($user);
        }

        return redirect()->intended(route('dashboard'));
    });
});

Route::post('logout', fn (AuthKitLogoutRequest $request) => $request->logout())
    ->middleware(['auth'])->name('logout');
