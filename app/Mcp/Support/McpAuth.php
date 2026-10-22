<?php

namespace App\Mcp\Support;

use App\Models\User;
use App\Services\Billing\UsageBillingService;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;

final class McpAuth
{
    public const CURATOR_ABILITY = 'curator';

    public const CURATOR_TOKEN_NAME = 'curator';

    public static function user(Request $request): User|Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return Response::error('Unauthenticated. Pass Authorization: Bearer <token>.');
        }

        return $user;
    }

    public static function isCurator(User $user): bool
    {
        if (! method_exists($user, 'currentSanctumToken')) {
            return false;
        }

        $token = $user->currentSanctumToken();
        if ($token === null) {
            return false;
        }

        // Name match is authoritative. Do not use Sanctum can() - mcp tokens
        // default to abilities ['*'], which would treat every user PAT as curator.
        if ($token->name === self::CURATOR_TOKEN_NAME) {
            return true;
        }

        $abilities = $token->abilities ?? [];

        return is_array($abilities) && in_array(self::CURATOR_ABILITY, $abilities, true);
    }

    public static function requireCurator(User $user): ?Response
    {
        if (self::isCurator($user)) {
            return null;
        }

        return Response::error('This tool requires a curator token.');
    }

    /**
     * Resolve the data subject: the actor, or another user when the actor holds a curator token.
     */
    public static function subjectUser(User $actor, ?int $userId): User|Response
    {
        if ($userId === null || $userId === (int) $actor->id) {
            return $actor;
        }

        if (! self::isCurator($actor)) {
            return Response::error('user_id is only available to curator tokens.');
        }

        $subject = User::query()->find($userId);
        if ($subject === null) {
            return Response::error('User not found.');
        }

        return $subject;
    }

    public static function requireProductAccess(User $user, ?User $actor = null): ?Response
    {
        if ($actor !== null && self::isCurator($actor)) {
            return null;
        }

        if (self::isCurator($user)) {
            return null;
        }

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
