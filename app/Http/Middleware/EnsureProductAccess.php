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
     * - Safe Inertia/HTML GETs still render the page shell + paywall UI, but
     *   controllers must omit product data (empty stubs only). Skip the
     *   billing check here so deferred partial reloads stay cheap; controllers
     *   call productAccessBlocked() when building props.
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

        // Safe HTML / Inertia GETs: controllers omit product data.
        if ($request->isMethodSafe() && ($isInertia || ! $request->expectsJson())) {
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

        Inertia::flash('toast', [
            'type' => 'error',
            'message' => $message,
        ]);

        return redirect()->route('billing.edit');
    }
}
