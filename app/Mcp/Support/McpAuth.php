<?php

namespace App\Mcp\Support;

use App\Models\AutomationRun;
use App\Models\User;
use App\Services\Automations\AutomationRunTokenIssuer;
use App\Services\Billing\UsageBillingService;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Sanctum\PersonalAccessToken;

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

    public static function currentToken(User $user): ?PersonalAccessToken
    {
        if (! method_exists($user, 'currentSanctumToken')) {
            return null;
        }

        return $user->currentSanctumToken();
    }

    public static function isCurator(User $user): bool
    {
        $token = self::currentToken($user);
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

    public static function isAutomation(User $user): bool
    {
        $token = self::currentToken($user);
        if ($token === null) {
            return false;
        }

        return str_starts_with((string) $token->name, AutomationRunTokenIssuer::TOKEN_NAME_PREFIX);
    }

    public static function automationRun(User $user): ?AutomationRun
    {
        $token = self::currentToken($user);
        if ($token === null) {
            return null;
        }

        return app(AutomationRunTokenIssuer::class)->findRunForToken($token);
    }

    public static function requireCurator(User $user): ?Response
    {
        if (self::isCurator($user)) {
            return null;
        }

        return Response::error('This tool requires a curator token.');
    }

    public static function requireAutomationAbility(User $user, string $ability): User|Response
    {
        if (! self::isAutomation($user)) {
            return Response::error('This tool requires an automation run token.');
        }

        $run = self::automationRun($user);
        if ($run === null || ! $run->isOpen()) {
            return Response::error('Automation run is missing or already closed.');
        }

        if ($run->expires_at !== null && $run->expires_at->isPast()) {
            app(AutomationRunTokenIssuer::class)->complete(
                $run,
                AutomationRun::STATUS_TIMED_OUT,
                null,
                'Scoped automation token expired.',
            );

            return Response::error('Automation run token expired.');
        }

        $abilities = is_array($run->abilities) ? $run->abilities : [];
        if (! in_array($ability, $abilities, true)) {
            return Response::error('Automation token lacks ability '.$ability.'.');
        }

        if ((int) $run->user_id !== (int) $user->id) {
            return Response::error('Automation token user mismatch.');
        }

        return $user;
    }

    /**
     * Resolve the data subject: the actor, or another user when the actor holds a curator token.
     * Automation tokens always resolve to the token owner and ignore any requested user_id.
     */
    public static function subjectUser(User $actor, ?int $userId): User|Response
    {
        // Per-run automation tokens are bound to one user. Ignore any user_id
        // in tool args so a crafted body cannot pivot to another account.
        if (self::isAutomation($actor)) {
            return $actor;
        }

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
        if ($actor !== null && (self::isCurator($actor) || self::isAutomation($actor))) {
            return null;
        }

        if (self::isCurator($user) || self::isAutomation($user)) {
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
