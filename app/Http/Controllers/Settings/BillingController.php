<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Services\Billing\PlanEntitlementService;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class BillingController extends Controller
{
    public function __construct(private PlanEntitlementService $entitlements) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        $this->entitlements->ensureTrialStarted($user);
        $user->refresh();

        $plans = collect(config('subscriptions.plans', []))
            ->map(fn (array $plan, string $key): array => [
                'key' => $key,
                'name' => $plan['name'],
                'price_pence' => (int) $plan['price_pence'],
                'competitor_limit' => (int) $plan['competitor_limit'],
                'has_checkout' => in_array($key, ['basic', 'pro'], true)
                    && filled($plan['stripe_price'] ?? null),
            ])
            ->values()
            ->all();

        return Inertia::render('billing/Index', [
            'subscription' => $this->entitlements->summary($user),
            'plans' => $plans,
        ]);
    }

    public function checkout(Request $request): Responsable|RedirectResponse
    {
        $data = $request->validate([
            'plan' => ['required', 'string', Rule::in(['basic', 'pro'])],
        ]);

        $user = $request->user();
        $priceId = $this->entitlements->stripePriceIdForPlan($data['plan']);

        if ($priceId === null) {
            Inertia::flash('toast', [
                'type' => 'error',
                'message' => __('Billing is not configured for that plan yet.'),
            ]);

            return redirect()->route('billing.edit');
        }

        $type = (string) config('subscriptions.subscription_type', 'default');

        return $user->newSubscription($type, $priceId)
            ->checkout([
                'success_url' => route('billing.edit').'?checkout=success',
                'cancel_url' => route('billing.edit').'?checkout=cancelled',
            ]);
    }

    public function portal(Request $request): RedirectResponse
    {
        $user = $request->user();

        if (! $user->hasStripeId()) {
            Inertia::flash('toast', [
                'type' => 'info',
                'message' => __('No Stripe customer yet. Subscribe to a plan first.'),
            ]);

            return redirect()->route('billing.edit');
        }

        return $user->redirectToBillingPortal(route('billing.edit'));
    }
}
