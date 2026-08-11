<?php

namespace App\Http\Middleware;

use App\Services\Billing\UsageBillingService;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

class EnsureProductAccess
{
    public function __construct(private UsageBillingService $usage) {}

    /**
     * Gate product surfaces when paywalled.
     *
     * - Mutations redirect to Billing (or 402 for JSON).
     * - Non-Inertia JSON/XHR (status polls, etc.) return 402 with no payload.
     * - Safe Inertia GETs still render the page shell + paywall UI; controllers
     *   omit product data (empty stubs). Skip the billing query here so deferred
     *   partial reloads stay cheap - controllers enforce omission when needed.
     *
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            return $next($request);
        }

        $isInertia = (bool) $request->header('X-Inertia');

        // Inertia page GETs: controllers omit product data when blocked.
        if ($request->isMethodSafe() && $isInertia) {
            return $next($request);
        }

        if ($this->usage->canAccessProduct($user)) {
            return $next($request);
        }

        $paywall = $this->usage->paywallState($user);
        $message = $paywall['message'] ?? 'Subscribe to a paid plan on the Billing page to continue.';

        if ($request->expectsJson() && ! $isInertia) {
            return response()->json([
                'message' => $message,
                'paywall' => $paywall,
            ], 402);
        }

        if ($request->isMethodSafe()) {
            return $next($request);
        }

        Inertia::flash('toast', [
            'type' => 'error',
            'message' => $message,
        ]);

        return redirect()->route('billing.edit');
    }
}
