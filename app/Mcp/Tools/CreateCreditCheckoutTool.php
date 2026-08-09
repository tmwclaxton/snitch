<?php

namespace App\Mcp\Tools;

use App\Mcp\Support\McpAuth;
use App\Services\Billing\PlanEntitlementService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('create_credit_checkout')]
#[Description('Create a Stripe Checkout URL to top up usage credits (pack_10, pack_25, pack_50, pack_100).')]
class CreateCreditCheckoutTool extends Tool
{
    public function handle(Request $request, PlanEntitlementService $entitlements): Response
    {
        $user = McpAuth::user($request);
        if ($user instanceof Response) {
            return $user;
        }

        $data = $request->validate([
            'pack' => ['required', 'string', 'in:pack_10,pack_25,pack_50,pack_100'],
        ]);

        $priceId = $entitlements->creditPackStripePriceId($data['pack']);
        $creditsPence = (int) config("billing.credit_packs.{$data['pack']}.credits_pence", 0);
        if ($priceId === null || $creditsPence <= 0) {
            return Response::error('Credit pack is not configured.');
        }

        $checkout = $user->checkout([$priceId], [
            'success_url' => route('billing.edit').'?checkout=credits_success',
            'cancel_url' => route('billing.edit').'?checkout=cancelled',
            'metadata' => [
                'snitch_product' => 'credits',
                'credit_pack' => $data['pack'],
                'credits_pence' => (string) $creditsPence,
                'user_id' => (string) $user->id,
            ],
        ]);

        return Response::json([
            'checkout_url' => $checkout->asStripeCheckoutSession()->url,
            'credits_pence' => $creditsPence,
        ]);
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'pack' => $schema->string()->description('Credit pack key')->required(),
        ];
    }
}
