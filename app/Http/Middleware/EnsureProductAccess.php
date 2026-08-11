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
     * - Safe Inertia GETs still render the page shell + paywall UI, but
     *   controllers must omit product data (empty stubs only).
     *
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || $this->usage->canAccessProduct($user)) {
            return $next($request);
        }

        $paywall = $this->usage->paywallState($user);
        $message = $paywall['message'] ?? 'Subscribe to a paid plan on the Billing page to continue.';
        $isInertia = (bool) $request->header('X-Inertia');

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
