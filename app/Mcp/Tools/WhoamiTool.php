<?php

namespace App\Mcp\Tools;

use App\Mcp\Support\BrandContext;
use App\Mcp\Support\McpAuth;
use App\Mcp\Support\McpRuntime;
use App\Services\Billing\PlanEntitlementService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('whoami')]
#[Description('Return the authenticated Snitch user, subscription/credit summary, brand readiness warnings, and runtime context (app_url / queue health). Call this first to confirm you are on the intended environment (local vs production).')]
class WhoamiTool extends Tool
{
    public function handle(Request $request, PlanEntitlementService $entitlements): Response
    {
        $user = McpAuth::user($request);
        if ($user instanceof Response) {
            return $user;
        }

        $runtime = McpRuntime::snapshot();
        $brandWarnings = BrandContext::warningsFor($user);

        return Response::json([
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'claimed' => $user->isClaimed(),
            'created_via' => $user->created_via,
            'subscription' => $entitlements->summary($user),
            'brand_warnings' => $brandWarnings,
            'runtime' => $runtime,
            'next_step' => $brandWarnings !== []
                ? 'Fix brand_warnings (update_brand / start_brand_autofill), then billing_status.'
                : 'Call billing_status, then proceed with brand/competitors/influencers loops. Complete confirm/keep steps before ending.',
        ]);
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
