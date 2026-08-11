<?php

namespace App\Http\Controllers\Settings;

use App\Enums\BillingVendor;
use App\Exceptions\PlatformSubscriptionRequiredException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\ListBillingChargesRequest;
use App\Models\User;
use App\Services\Billing\PlanEntitlementService;
use App\Services\Billing\UsageBillingService;
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
    public function __construct(
        private PlanEntitlementService $entitlements,
        private UsageBillingService $usage,
    ) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        $data = $request->validate([
            'grain' => ['sometimes', 'nullable', 'string', Rule::in(['day', 'week', 'month'])],
        ]);
        $grain = is_string($data['grain'] ?? null) ? $data['grain'] : 'day';

        $packs = collect(config('billing.credit_packs', []))
            ->map(fn (array $pack, string $key): array => [
                'key' => $key,
                'name' => $pack['name'],
                'credits_pence' => (int) $pack['credits_pence'],
                'price_pence' => (int) $pack['price_pence'],
                'has_checkout' => filled($pack['stripe_price'] ?? null),
            ])
            ->values()
            ->all();

        return Inertia::render('billing/Index', [
            'subscription' => $this->entitlements->sharedSummary($user),
            'usage' => $this->usage->summary($user),
            'spendSeries' => Inertia::defer(fn () => $this->usage->spendSeries($user, $grain), 'chart'),
            'creditPacks' => $packs,
            'platform' => [
                'fee_pence' => (int) config('billing.platform_fee_pence', 1900),
                'bonus_pence' => (int) config('billing.subscription_bonus_pence', 3000),
                'has_checkout' => filled(config('billing.platform_stripe_price')),
            ],
        ]);
    }

    public function charges(ListBillingChargesRequest $request): Response
    {
        $user = $request->user();
        $filters = $request->filters();

        return Inertia::render('billing/Charges', [
            'charges' => Inertia::defer(fn () => $this->usage->paginatedCharges($user, $filters)),
            'filters' => $filters,
            'vendors' => collect(BillingVendor::cases())
                ->map(fn (BillingVendor $vendor): string => $vendor->value)
                ->values()
                ->all(),
            'actions' => $this->usage->ledgerActionOptions(),
            'usage' => [
                'balance_pence' => $this->usage->balancePence($user),
            ],
        ]);
    }

    public function checkout(Request $request): SymfonyResponse|RedirectResponse
    {
        $data = $request->validate([
            'product' => ['required', 'string', Rule::in(['platform', 'credits'])],
            'pack' => ['nullable', 'string', Rule::in(array_keys(config('billing.credit_packs', [])))],
        ]);

        $user = $request->user();

        try {
            if ($data['product'] === 'platform') {
                return $this->checkoutPlatform($user);
            }

            return $this->checkoutCredits($user, (string) ($data['pack'] ?? ''));
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
                'message' => __('No Stripe customer yet. Subscribe to the platform plan first.'),
            ]);

            return redirect()->route('billing.edit');
        }

        return Inertia::location($user->billingPortalUrl(route('billing.edit')));
    }

    private function checkoutPlatform(User $user): SymfonyResponse|RedirectResponse
    {
        $priceId = $this->entitlements->platformStripePriceId();

        if ($priceId === null) {
            Inertia::flash('toast', [
                'type' => 'error',
                'message' => __('Billing is not configured for the platform plan yet.'),
            ]);

            return redirect()->route('billing.edit');
        }

        $type = (string) config('billing.subscription_type', 'default');

        if ($user->subscribed($type)) {
            Inertia::flash('toast', [
                'type' => 'info',
                'message' => __('You already have an active platform plan.'),
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

        return Inertia::location($url);
    }

    private function checkoutCredits(User $user, string $packKey): SymfonyResponse|RedirectResponse
    {
        if ($packKey === '') {
            Inertia::flash('toast', [
                'type' => 'error',
                'message' => __('Choose a credit pack.'),
            ]);

            return redirect()->route('billing.edit');
        }

        try {
            $this->usage->assertCanTopUp($user);
        } catch (PlatformSubscriptionRequiredException $exception) {
            Inertia::flash('toast', [
                'type' => 'error',
                'message' => $exception->getMessage(),
            ]);

            return redirect()->route('billing.edit');
        }

        $priceId = $this->entitlements->creditPackStripePriceId($packKey);
        $creditsPence = (int) config("billing.credit_packs.{$packKey}.credits_pence", 0);

        if ($priceId === null || $creditsPence <= 0) {
            Inertia::flash('toast', [
                'type' => 'error',
                'message' => __('That credit pack is not configured yet.'),
            ]);

            return redirect()->route('billing.edit');
        }

        $checkout = $user->checkout([$priceId], [
            'success_url' => route('billing.edit').'?checkout=credits_success',
            'cancel_url' => route('billing.edit').'?checkout=cancelled',
            'metadata' => [
                'snitch_product' => 'credits',
                'credit_pack' => $packKey,
                'credits_pence' => (string) $creditsPence,
                'user_id' => (string) $user->id,
            ],
        ]);

        $url = $this->checkoutUrl($checkout);

        if ($url === null) {
            Inertia::flash('toast', [
                'type' => 'error',
                'message' => __('Could not start Stripe Checkout. Try again in a moment.'),
            ]);

            return redirect()->route('billing.edit');
        }

        return Inertia::location($url);
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
