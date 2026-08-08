<?php

namespace App\Http\Middleware;

use App\Services\Billing\PlanEntitlementService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureBrandProfile
{
    /**
     * Redirect authenticated users without a brand profile to onboarding.
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

        if ($user->brandProfile()->exists()) {
            return $next($request);
        }

        if ($request->routeIs('onboarding.*', 'logout')) {
            return $next($request);
        }

        return redirect()->route('onboarding.show');
    }
}
