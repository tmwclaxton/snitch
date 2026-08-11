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
     * Block product mutations when paywalled. Billing / settings / onboarding stay open.
     * GET pages still render so the UI can blur data and show the paywall modal.
     *
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || $request->isMethodSafe()) {
            return $next($request);
        }

        if ($this->usage->canAccessProduct($user)) {
            return $next($request);
        }

        $paywall = $this->usage->paywallState($user);
        $message = $paywall['message'] ?? 'Subscribe to a paid plan on the Billing page to continue.';

        Inertia::flash('toast', [
            'type' => 'error',
            'message' => $message,
        ]);

        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return response()->json([
                'message' => $message,
                'paywall' => $paywall,
            ], 402);
        }

        return redirect()->route('billing.edit');
    }
}
