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

        if ($type !== 'checkout.session.completed') {
            return;
        }

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
}
