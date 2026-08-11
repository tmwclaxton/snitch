<?php

namespace App\Mcp\Support;

use App\Models\User;
use App\Services\Billing\UsageBillingService;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;

final class McpAuth
{
    public static function user(Request $request): User|Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return Response::error('Unauthenticated. Pass Authorization: Bearer <token>.');
        }

        return $user;
    }

    public static function requireProductAccess(User $user): ?Response
    {
        $usage = app(UsageBillingService::class);

        if ($usage->canAccessProduct($user)) {
            return null;
        }

        $paywall = $usage->paywallState($user);

        return Response::error(
            $paywall['message'] ?? 'Subscribe to a paid plan to continue using Snitch MCP.',
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function json(array $payload): Response
    {
        return Response::json($payload);
    }
}
