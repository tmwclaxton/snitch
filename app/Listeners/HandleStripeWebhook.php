<?php

namespace App\Listeners;

use App\Models\User;
use App\Services\Billing\UsageBillingService;
use Illuminate\Support\Facades\Log;
use Laravel\Cashier\Events\WebhookReceived;

class HandleStripeWebhook
{
    public function __construct(private UsageBillingService $usage) {}

    public function handle(WebhookReceived $event): void
    {
        $payload = $event->payload;
        $type = $payload['type'] ?? null;

        if ($type === 'checkout.session.completed') {
            $this->handleCreditCheckout($payload);

            return;
        }

        if ($type === 'invoice.paid') {
            $this->handleSubscriptionInvoicePaid($payload);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function handleCreditCheckout(array $payload): void
    {
        $session = $payload['data']['object'] ?? null;

        if (! is_array($session)) {
            return;
        }

        $metadata = is_array($session['metadata'] ?? null) ? $session['metadata'] : [];

        if (($metadata['snitch_product'] ?? null) !== 'credits') {
            return;
        }

        $userId = (int) ($metadata['user_id'] ?? 0);
        $creditsPence = (int) ($metadata['credits_pence'] ?? 0);
        $sessionId = is_string($session['id'] ?? null) ? $session['id'] : null;

        if ($userId <= 0 || $creditsPence <= 0 || $sessionId === null) {
            Log::warning('Stripe credit top-up webhook missing fields', [
                'metadata' => $metadata,
            ]);

            return;
        }

        $user = User::query()->find($userId);

        if ($user === null) {
            return;
        }

        $this->usage->creditFromTopUp(
            user: $user,
            creditsPence: $creditsPence,
            idempotencyKey: 'stripe.checkout:'.$sessionId,
            meta: [
                'credit_pack' => $metadata['credit_pack'] ?? null,
                'stripe_session_id' => $sessionId,
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function handleSubscriptionInvoicePaid(array $payload): void
    {
        $invoice = $payload['data']['object'] ?? null;

        if (! is_array($invoice)) {
            return;
        }

        if (($invoice['status'] ?? null) !== 'paid') {
            return;
        }

        $invoiceId = is_string($invoice['id'] ?? null) ? $invoice['id'] : null;
        $customerId = is_string($invoice['customer'] ?? null) ? $invoice['customer'] : null;

        if ($invoiceId === null || $customerId === null) {
            return;
        }

        if (! $this->invoiceIncludesPlatformPrice($invoice)) {
            return;
        }

        $user = User::query()->where('stripe_id', $customerId)->first();

        if ($user === null) {
            Log::warning('Stripe subscription invoice paid for unknown customer', [
                'customer' => $customerId,
                'invoice' => $invoiceId,
            ]);

            return;
        }

        $this->usage->creditSubscriptionBonus(
            user: $user,
            idempotencyKey: 'subscription_bonus:invoice:'.$invoiceId,
            meta: [
                'stripe_invoice_id' => $invoiceId,
                'billing_reason' => $invoice['billing_reason'] ?? null,
                'amount_paid' => $invoice['amount_paid'] ?? null,
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
