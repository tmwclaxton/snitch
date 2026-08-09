<?php

namespace App\Mcp\Tools;

use App\Mcp\Support\McpAuth;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('billing_portal')]
#[Description('Create a Stripe Customer Portal URL for the authenticated user.')]
class BillingPortalTool extends Tool
{
    public function handle(Request $request): Response
    {
        $user = McpAuth::user($request);
        if ($user instanceof Response) {
            return $user;
        }

        if (! $user->hasStripeId()) {
            return Response::error('No Stripe customer yet. Subscribe to the platform plan first.');
        }

        return Response::json([
            'portal_url' => $user->billingPortalUrl(route('billing.edit')),
        ]);
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
