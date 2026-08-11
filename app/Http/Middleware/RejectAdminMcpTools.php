<?php

namespace App\Http\Middleware;

use App\Support\AdminMcp;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Admin overview and operator tooling are web-only (ADMIN_EMAILS + /admin).
 * Reject any MCP tools/call that targets an admin_* tool name.
 */
class RejectAdminMcpTools
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $payload = $request->all();
        $method = is_string($payload['method'] ?? null) ? $payload['method'] : null;

        if ($method !== 'tools/call') {
            return $next($request);
        }

        $params = is_array($payload['params'] ?? null) ? $payload['params'] : [];
        $tool = is_string($params['name'] ?? null) ? $params['name'] : null;

        if (! AdminMcp::isBlockedTool($tool)) {
            return $next($request);
        }

        return response()->json([
            'jsonrpc' => '2.0',
            'id' => $payload['id'] ?? null,
            'error' => [
                'code' => -32003,
                'message' => 'Admin actions are web-only and unavailable via MCP.',
                'data' => [
                    'tool' => $tool,
                    'admin' => true,
                ],
            ],
        ], 403);
    }
}
