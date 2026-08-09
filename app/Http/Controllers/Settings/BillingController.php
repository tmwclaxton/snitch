<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Services\Billing\PlanEntitlementService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Cashier\Checkout;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Throwable;

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
                'yearly_price_pence' => (int) ($plan['yearly_price_pence'] ?? 0),
                'competitor_limit' => (int) $plan['competitor_limit'],
                'influencer_limit' => (int) ($plan['influencer_limit'] ?? $plan['competitor_limit']),
                'has_checkout_month' => in_array($key, ['basic', 'pro'], true)
                    && filled($plan['stripe_price'] ?? null),
                'has_checkout_year' => in_array($key, ['basic', 'pro'], true)
                    && filled($plan['stripe_price_yearly'] ?? null),
            ])
            ->values()
            ->all();

        return Inertia::render('billing/Index', [
            'subscription' => $this->entitlements->summary($user),
            'plans' => $plans,
            'yearlyDiscountPercent' => (int) config('subscriptions.yearly_discount_percent', 20),
        ]);
    }

    public function checkout(Request $request): SymfonyResponse|RedirectResponse
    {
        $data = $request->validate([
            'plan' => ['required', 'string', Rule::in(['basic', 'pro'])],
            'interval' => ['required', 'string', Rule::in(['month', 'year'])],
        ]);

        $user = $request->user();
        $priceId = $this->entitlements->stripePriceIdForPlan($data['plan'], $data['interval']);

        if ($priceId === null) {
            Inertia::flash('toast', [
                'type' => 'error',
                'message' => __('Billing is not configured for that plan yet.'),
            ]);

            return redirect()->route('billing.edit');
        }

        $type = (string) config('subscriptions.subscription_type', 'default');

        try {
            if ($user->subscribed($type)) {
                $user->subscription($type)?->swapAndInvoice($priceId);

                Inertia::flash('toast', [
                    'type' => 'success',
                    'message' => __('Plan updated.'),
                ]);

                return redirect()->route('billing.edit');
            }

            $checkout = $user->newSubscription($type, $priceId)
                ->checkout([
                    'success_url' => route('billing.edit').'?checkout=success',
                    'cancel_url' => route('billing.edit').'?checkout=cancelled',
                ]);

            $url = $this->checkoutUrl($checkout);

            if ($url === null) {
                Inertia::flash('toast', [
                    'type' => 'error',
                    'message' => __('Could not start Stripe Checkout. Try again in a moment.'),
                ]);

                return redirect()->route('billing.edit');
            }

            // Inertia forms need an external Location response, not a plain 302.
            return Inertia::location($url);
        } catch (Throwable $e) {
            report($e);

            Inertia::flash('toast', [
                'type' => 'error',
                'message' => __('Checkout failed. Check Stripe is configured and try again.'),
            ]);

            return redirect()->route('billing.edit');
        }
    }

    public function portal(Request $request): RedirectResponse|SymfonyResponse
    {
        $user = $request->user();

        if (! $user->hasStripeId()) {
            Inertia::flash('toast', [
                'type' => 'info',
                'message' => __('No Stripe customer yet. Subscribe to a plan first.'),
            ]);

            return redirect()->route('billing.edit');
        }

        return Inertia::location($user->billingPortalUrl(route('billing.edit')));
    }

    private function checkoutUrl(mixed $checkout): ?string
    {
        if ($checkout instanceof Checkout) {
            $url = $checkout->asStripeCheckoutSession()->url;

            return is_string($url) && $url !== '' ? $url : null;
        }

        if ($checkout instanceof RedirectResponse) {
            return $checkout->getTargetUrl();
        }

        return null;
    }
}
