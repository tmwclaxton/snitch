<?php

namespace App\Services\Billing;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Laravel\Cashier\Cashier;
use Stripe\Checkout\Session as StripeCheckoutSession;
use Stripe\Subscription as StripeSubscription;
use Throwable;

/**
 * Sync Stripe Checkout / subscription state into local Cashier rows when the
 * user returns from Checkout. Webhooks remain the source of truth in
 * production; this covers local delay/missing Stripe CLI forwarding and race
 * on first paint after redirect.
 */
class StripeCheckoutSyncService
{
    public function __construct(
        private UsageBillingService $usage,
        private PlanEntitlementService $entitlements,
    ) {}

    public static function billingSuccessUrl(string $checkoutStatus = 'success'): string
    {
        return route('billing.edit').'?checkout='.$checkoutStatus.'&session_id={CHECKOUT_SESSION_ID}';
    }

    public static function billingCancelUrl(): string
    {
        return route('billing.edit').'?checkout=cancelled';
    }

    public function syncUserFromCheckoutReturn(User $user, ?string $sessionId = null): bool
    {
        if (is_string($sessionId) && $sessionId !== '') {
            return $this->syncFromCheckoutSessionId($user, $sessionId);
        }

        return $this->syncActiveSubscriptionsFromStripe($user);
    }

    public function syncFromCheckoutSessionId(User $user, string $sessionId): bool
    {
        try {
            $session = Cashier::stripe()->checkout->sessions->retrieve($sessionId, [
                'expand' => ['subscription', 'subscription.items.data.price', 'invoice'],
            ]);
        } catch (Throwable $exception) {
            Log::warning('Stripe checkout session retrieve failed', [
                'user_id' => $user->id,
                'session_id' => $sessionId,
                'message' => $exception->getMessage(),
            ]);

            return false;
        }

        return $this->applyCheckoutSession($user, $session->toArray());
    }

    /**
     * @param  array<string, mixed>  $session
     */
    public function applyCheckoutSession(User $user, array $session): bool
    {
        $customerId = is_string($session['customer'] ?? null) ? $session['customer'] : null;

        if ($customerId !== null && $user->hasStripeId() && $user->stripe_id !== $customerId) {
            Log::warning('Stripe checkout session customer mismatch', [
                'user_id' => $user->id,
                'session_id' => $session['id'] ?? null,
                'expected' => $user->stripe_id,
                'actual' => $customerId,
            ]);

            return false;
        }

        if ($customerId !== null && ! $user->hasStripeId()) {
            $user->forceFill(['stripe_id' => $customerId])->save();
        }

        $metadata = is_array($session['metadata'] ?? null) ? $session['metadata'] : [];
        $applied = false;

        $subscriptionPayload = $this->subscriptionPayloadFromSession($session);

        if ($subscriptionPayload !== null) {
            $this->syncStripeSubscription($user, $subscriptionPayload);
            $this->creditPlatformBonusFromSession($user, $session, $subscriptionPayload);
            $applied = true;
        }

        if (($metadata['snitch_product'] ?? null) === 'credits') {
            if (! $this->usage->hasPlatformSubscription($user)) {
                // Local plan row may still be missing if subscription webhooks never arrived.
                $applied = $this->syncActiveSubscriptionsFromStripe($user) || $applied;
            }

            $applied = $this->applyCreditCheckout($user, $session, $metadata) || $applied;
        }

        if ($applied) {
            $this->entitlements->forgetSharedSummary($user);
        }

        return $applied;
    }

    public function syncActiveSubscriptionsFromStripe(User $user): bool
    {
        if (! $user->hasStripeId()) {
            return false;
        }

        try {
            $subscriptions = Cashier::stripe()->subscriptions->all([
                'customer' => $user->stripe_id,
                'status' => 'all',
                'limit' => 20,
                'expand' => ['data.items.data.price'],
            ]);
        } catch (Throwable $exception) {
            Log::warning('Stripe subscription list failed during checkout return sync', [
                'user_id' => $user->id,
                'message' => $exception->getMessage(),
            ]);

            return false;
        }

        $applied = false;

        foreach ($subscriptions->data as $subscription) {
            if (! $subscription instanceof StripeSubscription) {
                continue;
            }

            if (! in_array($subscription->status, [
                StripeSubscription::STATUS_ACTIVE,
                StripeSubscription::STATUS_TRIALING,
                StripeSubscription::STATUS_PAST_DUE,
            ], true)) {
                continue;
            }

            $payload = $subscription->toArray();
            $this->syncStripeSubscription($user, $payload);
            $this->creditPlatformBonusFromSubscription($user, $payload);
            $applied = true;
        }

        if ($applied) {
            $this->entitlements->forgetSharedSummary($user);
        }

        return $applied;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function syncStripeSubscription(User $user, array $data): void
    {
        $stripeId = is_string($data['id'] ?? null) ? $data['id'] : null;

        if ($stripeId === null) {
            return;
        }

        if (($data['status'] ?? null) === StripeSubscription::STATUS_INCOMPLETE_EXPIRED) {
            $existing = $user->subscriptions()->where('stripe_id', $stripeId)->first();
            $existing?->items()->delete();
            $existing?->delete();

            return;
        }

        $items = is_array($data['items']['data'] ?? null) ? $data['items']['data'] : [];
        $firstItem = is_array($items[0] ?? null) ? $items[0] : null;
        $isSinglePrice = count($items) === 1;
        $trialEndsAt = isset($data['trial_end']) && $data['trial_end']
            ? Carbon::createFromTimestamp((int) $data['trial_end'])
            : null;

        $subscription = $user->subscriptions()->updateOrCreate([
            'stripe_id' => $stripeId,
        ], [
            'type' => $data['metadata']['type'] ?? $data['metadata']['name'] ?? (string) config('billing.subscription_type', 'default'),
            'stripe_status' => $data['status'] ?? StripeSubscription::STATUS_INCOMPLETE,
            'stripe_price' => $isSinglePrice && is_string($firstItem['price']['id'] ?? null)
                ? $firstItem['price']['id']
                : null,
            'quantity' => $isSinglePrice && isset($firstItem['quantity'])
                ? (int) $firstItem['quantity']
                : null,
            'trial_ends_at' => $trialEndsAt,
            'ends_at' => null,
        ]);

        foreach ($items as $item) {
            if (! is_array($item) || ! is_string($item['id'] ?? null)) {
                continue;
            }

            $subscription->items()->updateOrCreate([
                'stripe_id' => $item['id'],
            ], [
                'stripe_product' => $item['price']['product'] ?? null,
                'stripe_price' => $item['price']['id'] ?? null,
                'quantity' => isset($item['quantity']) ? (int) $item['quantity'] : null,
            ]);
        }

        if (! is_null($user->trial_ends_at)) {
            $user->forceFill(['trial_ends_at' => null])->save();
        }

        // Cashier's subscription()/subscribed() read the in-memory relation.
        $user->unsetRelation('subscriptions');
    }

    /**
     * @param  array<string, mixed>  $session
     * @param  array<string, mixed>  $metadata
     */
    private function applyCreditCheckout(User $user, array $session, array $metadata): bool
    {
        if (($session['payment_status'] ?? null) !== StripeCheckoutSession::PAYMENT_STATUS_PAID
            && ($session['status'] ?? null) !== StripeCheckoutSession::STATUS_COMPLETE) {
            return false;
        }

        $creditsPence = (int) ($metadata['credits_pence'] ?? 0);
        $sessionId = is_string($session['id'] ?? null) ? $session['id'] : null;

        if ($creditsPence <= 0 || $sessionId === null) {
            return false;
        }

        if (! $this->usage->hasPlatformSubscription($user)) {
            Log::warning('Stripe credit checkout return ignored: user has no paid plan', [
                'user_id' => $user->id,
                'session' => $sessionId,
            ]);

            return false;
        }

        $this->usage->creditFromTopUp(
            user: $user,
            creditsPence: $creditsPence,
            idempotencyKey: 'stripe.checkout:'.$sessionId,
            meta: [
                'credit_pack' => $metadata['credit_pack'] ?? null,
                'stripe_session_id' => $sessionId,
                'synced_from' => 'checkout_return',
            ],
        );

        return true;
    }

    /**
     * @param  array<string, mixed>  $session
     * @return array<string, mixed>|null
     */
    private function subscriptionPayloadFromSession(array $session): ?array
    {
        $subscription = $session['subscription'] ?? null;

        if (is_array($subscription) && is_string($subscription['id'] ?? null)) {
            return $subscription;
        }

        if (! is_string($subscription) || $subscription === '') {
            return null;
        }

        try {
            return Cashier::stripe()->subscriptions->retrieve($subscription, [
                'expand' => ['items.data.price'],
            ])->toArray();
        } catch (Throwable $exception) {
            Log::warning('Stripe subscription retrieve failed during checkout return sync', [
                'subscription' => $subscription,
                'message' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $session
     * @param  array<string, mixed>  $subscription
     */
    private function creditPlatformBonusFromSession(User $user, array $session, array $subscription): void
    {
        $invoice = $session['invoice'] ?? null;

        if (is_array($invoice)) {
            $this->creditPlatformBonusFromInvoice($user, $invoice);

            return;
        }

        if (is_string($invoice) && $invoice !== '') {
            $this->creditPlatformBonusFromInvoiceId($user, $invoice);

            return;
        }

        $this->creditPlatformBonusFromSubscription($user, $subscription);
    }

    /**
     * @param  array<string, mixed>  $subscription
     */
    private function creditPlatformBonusFromSubscription(User $user, array $subscription): void
    {
        $invoice = $subscription['latest_invoice'] ?? null;

        if (is_array($invoice)) {
            $this->creditPlatformBonusFromInvoice($user, $invoice);

            return;
        }

        if (is_string($invoice) && $invoice !== '') {
            $this->creditPlatformBonusFromInvoiceId($user, $invoice);
        }
    }

    private function creditPlatformBonusFromInvoiceId(User $user, string $invoiceId): void
    {
        try {
            $invoice = Cashier::stripe()->invoices->retrieve($invoiceId)->toArray();
        } catch (Throwable $exception) {
            Log::warning('Stripe invoice retrieve failed during checkout return sync', [
                'user_id' => $user->id,
                'invoice' => $invoiceId,
                'message' => $exception->getMessage(),
            ]);

            return;
        }

        $this->creditPlatformBonusFromInvoice($user, $invoice);
    }

    /**
     * @param  array<string, mixed>  $invoice
     */
    private function creditPlatformBonusFromInvoice(User $user, array $invoice): void
    {
        if (($invoice['status'] ?? null) !== 'paid') {
            return;
        }

        $invoiceId = is_string($invoice['id'] ?? null) ? $invoice['id'] : null;

        if ($invoiceId === null || ! $this->invoiceIncludesPlatformPrice($invoice)) {
            return;
        }

        $this->usage->creditSubscriptionBonus(
            user: $user,
            idempotencyKey: 'subscription_bonus:invoice:'.$invoiceId,
            meta: [
                'stripe_invoice_id' => $invoiceId,
                'billing_reason' => $invoice['billing_reason'] ?? null,
                'amount_paid' => $invoice['amount_paid'] ?? null,
                'synced_from' => 'checkout_return',
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $invoice
     */
    private function invoiceIncludesPlatformPrice(array $invoice): bool
    {
        $platformPrice = config('billing.platform_stripe_price');

        if (! is_string($platformPrice) || $platformPrice === '') {
            return false;
        }

        $lines = $invoice['lines']['data'] ?? null;

        if (! is_array($lines)) {
            return false;
        }

        foreach ($lines as $line) {
            if (! is_array($line)) {
                continue;
            }

            $priceId = $line['price']['id'] ?? $line['pricing']['price_details']['price'] ?? null;

            if ($priceId === $platformPrice) {
                return true;
            }
        }

        return false;
    }
}
