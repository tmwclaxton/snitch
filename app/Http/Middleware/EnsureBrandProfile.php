<?php

namespace App\Http\Middleware;

use App\Services\Billing\PlanEntitlementService;
use App\Services\Billing\UsageBillingService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureBrandProfile
{
    /**
     * Redirect authenticated users who still need onboarding or checkout.
     *
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            return $next($request);
        }

        app(PlanEntitlementService::class)->ensureTrialStarted($user);

        if ($request->routeIs('onboarding.*', 'logout', 'billing.*')) {
            return $next($request);
        }

        if (! $user->brandProfile()->exists()) {
            return redirect()->route('onboarding.show');
        }

        $usage = app(UsageBillingService::class);

        if ($usage->hasOperatorBypass($user) || $usage->hasPlatformSubscription($user)) {
            return $next($request);
        }

        if ($user->trackedAccounts()->competitors()->doesntExist()) {
            return redirect()->route('onboarding.show');
        }

        return redirect()->route('onboarding.show', ['step' => 'paywall']);
    }
}
