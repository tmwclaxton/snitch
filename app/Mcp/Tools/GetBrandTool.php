<?php

namespace App\Mcp\Tools;

use App\Mcp\Support\BrandContext;
use App\Mcp\Support\McpAuth;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('get_brand')]
#[Description('Get the authenticated user brand profile plus readiness warnings. Call whoami first to confirm environment (local vs production).')]
class GetBrandTool extends Tool
{
    public function handle(Request $request): Response
    {
        $user = McpAuth::user($request);
        if ($user instanceof Response) {
            return $user;
        }

        if ($blocked = McpAuth::requireProductAccess($user)) {
            return $blocked;
        }

        $brand = $user->brandProfile;
        $warnings = BrandContext::warningsFor($user);
        $blocking = BrandContext::blockingErrorsFor($user);

        return Response::json([
            'brand' => $brand?->only(['id', 'name', 'description', 'website', 'own_handles']),
            'brand_warnings' => $warnings,
            'brand_blocking' => $blocking,
            'next_step' => $blocking !== []
                ? 'Fix brand_blocking via update_brand or start_brand_autofill before suggest_competitors / find_influencers.'
                : ($warnings !== []
                    ? 'Brand is usable; review brand_warnings then proceed with suggest_competitors or find_influencers.'
                    : 'Brand looks ready. Proceed with suggest_competitors or find_influencers.'),
        ]);
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
