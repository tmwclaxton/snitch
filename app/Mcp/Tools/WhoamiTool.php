<?php

namespace App\Mcp\Tools;

use App\Mcp\Support\McpAuth;
use App\Services\Billing\PlanEntitlementService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Return the authenticated Snitch user and subscription/credit summary.')]
class WhoamiTool extends Tool
{
    public function handle(Request $request, PlanEntitlementService $entitlements): Response
    {
        $user = McpAuth::user($request);
        if ($user instanceof Response) {
            return $user;
        }

        return Response::json([
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'claimed' => $user->isClaimed(),
            'created_via' => $user->created_via,
            'subscription' => $entitlements->summary($user),
        ]);
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
