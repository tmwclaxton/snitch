<?php

namespace App\Mcp\Tools;

use App\Mcp\Support\McpAuth;
use App\Services\Billing\PlanEntitlementService;
use App\Services\Billing\StripeCheckoutSyncService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('create_platform_checkout')]
#[Description('Create a Stripe Checkout URL for the Snitch platform subscription.')]
class CreatePlatformCheckoutTool extends Tool
{
    public function handle(Request $request, PlanEntitlementService $entitlements): Response
    {
        $user = McpAuth::user($request);
        if ($user instanceof Response) {
            return $user;
        }

        $priceId = $entitlements->platformStripePriceId();
        if ($priceId === null) {
            return Response::error('Platform Stripe price is not configured.');
        }

        $type = (string) config('billing.subscription_type', 'default');
        if ($user->subscribed($type)) {
            return Response::json(['already_subscribed' => true]);
        }

        $checkout = $user->newSubscription($type, $priceId)->checkout([
            'success_url' => StripeCheckoutSyncService::billingSuccessUrl('success'),
            'cancel_url' => StripeCheckoutSyncService::billingCancelUrl(),
        ]);

        return Response::json([
            'checkout_url' => $checkout->asStripeCheckoutSession()->url,
        ]);
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
