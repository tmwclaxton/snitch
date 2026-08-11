<?php

namespace App\Http\Middleware;

use App\Models\McpToolInvocation;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Persist MCP tools/call metadata for admin analytics.
 * Never logs tokens or tool argument payloads.
 */
class LogMcpToolInvocation
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
        $tool = is_string($params['name'] ?? null) ? trim($params['name']) : '';

        if ($tool === '') {
            return $next($request);
        }

        $started = hrtime(true);
        $response = $next($request);
        $durationMs = (int) max(0, round((hrtime(true) - $started) / 1_000_000));

        $status = $response->getStatusCode();
        $errorCode = null;
        $ok = $status < 400;

        $content = $response->getContent();
        if (is_string($content) && $content !== '') {
            $decoded = json_decode($content, true);
            if (is_array($decoded) && isset($decoded['error']) && is_array($decoded['error'])) {
                $ok = false;
                $code = $decoded['error']['code'] ?? null;
                $errorCode = is_scalar($code) ? (string) $code : (string) $status;
            }
        }

        if (! $ok && $errorCode === null) {
            $errorCode = (string) $status;
        }

        $bearer = $request->bearerToken();
        $auth = null;
        if (is_string($bearer) && $bearer !== '') {
            $auth = str_contains($bearer, '|') ? 'sanctum' : 'passport';
        }

        try {
            McpToolInvocation::query()->create([
                'user_id' => $request->user()?->id,
                'tool' => mb_substr($tool, 0, 255),
                'ok' => $ok,
                'error_code' => $errorCode !== null ? mb_substr($errorCode, 0, 255) : null,
                'duration_ms' => $durationMs,
                'auth' => $auth,
            ]);
        } catch (\Throwable $e) {
            Log::warning('Failed to log MCP tool invocation', [
                'tool' => $tool,
                'error' => $e->getMessage(),
            ]);
        }

        return $response;
    }
}
