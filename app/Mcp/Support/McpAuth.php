<?php

namespace App\Mcp\Support;

use App\Models\User;
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

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function json(array $payload): Response
    {
        return Response::json($payload);
    }
}
