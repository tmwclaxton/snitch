<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateMcp
{
    /**
     * Accept Passport OAuth tokens (Claude.ai) or Sanctum bearer tokens (Cursor/agents).
     *
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($this->authenticateSanctum($request) || $this->authenticatePassport($request)) {
            return $next($request);
        }

        throw new AuthenticationException('Unauthenticated.');
    }

    protected function authenticatePassport(Request $request): bool
    {
        $bearerToken = $request->bearerToken();

        if ($bearerToken === null || $bearerToken === '' || str_contains($bearerToken, '|')) {
            return false;
        }

        Auth::shouldUse('api');

        return Auth::guard('api')->check();
    }

    protected function authenticateSanctum(Request $request): bool
    {
        $bearerToken = $request->bearerToken();

        if ($bearerToken === null || $bearerToken === '' || ! str_contains($bearerToken, '|')) {
            return false;
        }

        $accessToken = PersonalAccessToken::findToken($bearerToken);

        if ($accessToken === null) {
            return false;
        }

        if ($accessToken->expires_at !== null && $accessToken->expires_at->isPast()) {
            return false;
        }

        $user = $accessToken->tokenable;

        if ($user === null) {
            return false;
        }

        Auth::setUser($user);
        Auth::shouldUse('web');

        if (method_exists($accessToken->getConnection(), 'hasModifiedRecords')
            && method_exists($accessToken->getConnection(), 'setRecordModificationState')) {
            $hasModifiedRecords = $accessToken->getConnection()->hasModifiedRecords();
            $accessToken->forceFill(['last_used_at' => now()])->save();
            $accessToken->getConnection()->setRecordModificationState($hasModifiedRecords);
        } else {
            $accessToken->forceFill(['last_used_at' => now()])->save();
        }

        return true;
    }
}
