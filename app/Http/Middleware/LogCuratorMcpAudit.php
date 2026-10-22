<?php

namespace App\Http\Middleware;

use App\Mcp\Support\McpAuth;
use App\Models\CuratorMcpAudit;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Audit every MCP tools/call made with a curator token. Never logs tokens or full argument payloads.
 */
class LogCuratorMcpAudit
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user instanceof User || ! McpAuth::isCurator($user)) {
            return $next($request);
        }

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

        $arguments = is_array($params['arguments'] ?? null) ? $params['arguments'] : [];
        $subjectUserId = isset($arguments['user_id']) && is_numeric($arguments['user_id'])
            ? (int) $arguments['user_id']
            : null;

        $started = hrtime(true);
        $response = $next($request);
        $durationMs = (int) max(0, round((hrtime(true) - $started) / 1_000_000));

        $status = $response->getStatusCode();
        $ok = $status < 400;
        $errorCode = null;
        $content = $response->getContent();
        if (is_string($content) && $content !== '') {
            $decoded = json_decode($content, true);
            if (is_array($decoded) && isset($decoded['error']) && is_array($decoded['error'])) {
                $ok = false;
                $code = $decoded['error']['code'] ?? null;
                $errorCode = is_scalar($code) ? (string) $code : (string) $status;
            }
        }

        $token = method_exists($user, 'currentSanctumToken') ? $user->currentSanctumToken() : null;

        try {
            CuratorMcpAudit::query()->create([
                'actor_user_id' => $user->id,
                'token_id' => $token?->id,
                'tool' => mb_substr($tool, 0, 255),
                'subject_user_id' => $subjectUserId,
                'ok' => $ok,
                'error_code' => $errorCode !== null ? mb_substr($errorCode, 0, 255) : null,
                'duration_ms' => $durationMs,
                'meta' => [
                    'has_user_id' => $subjectUserId !== null,
                    'argument_keys' => array_values(array_keys($arguments)),
                ],
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Failed to log curator MCP audit', [
                'tool' => $tool,
                'error' => $e->getMessage(),
            ]);
        }

        return $response;
    }
}
