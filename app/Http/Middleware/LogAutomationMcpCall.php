<?php

namespace App\Http\Middleware;

use App\Mcp\Support\McpAuth;
use App\Models\AutomationCallAudit;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rate-limit and audit MCP tools/call for per-run automation tokens.
 * Never logs tokens or argument payloads. User scope comes only from the token.
 */
class LogAutomationMcpCall
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

        $user = $request->user();
        if (! $user instanceof User || ! McpAuth::isAutomation($user)) {
            return $next($request);
        }

        $run = McpAuth::automationRun($user);
        if ($run === null) {
            return response()->json([
                'jsonrpc' => '2.0',
                'error' => [
                    'code' => -32001,
                    'message' => 'Automation run not found for this token.',
                ],
                'id' => $payload['id'] ?? null,
            ], 403);
        }

        $limitKey = 'automation-mcp:'.$user->id;
        if (RateLimiter::tooManyAttempts($limitKey, 60)) {
            return response()->json([
                'jsonrpc' => '2.0',
                'error' => [
                    'code' => -32002,
                    'message' => 'Automation rate limit exceeded for this user.',
                ],
                'id' => $payload['id'] ?? null,
            ], 429);
        }
        RateLimiter::hit($limitKey, 60);

        $params = is_array($payload['params'] ?? null) ? $payload['params'] : [];
        $tool = is_string($params['name'] ?? null) ? trim($params['name']) : '';

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
        if (! $ok && $errorCode === null) {
            $errorCode = (string) $status;
        }

        try {
            AutomationCallAudit::query()->create([
                'automation_run_id' => $run->id,
                'user_id' => $user->id,
                'tool' => mb_substr($tool !== '' ? $tool : 'unknown', 0, 120),
                'ok' => $ok,
                'error_code' => $errorCode !== null ? mb_substr($errorCode, 0, 64) : null,
                'duration_ms' => $durationMs,
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Failed to log automation MCP call', [
                'tool' => $tool,
                'error' => $e->getMessage(),
            ]);
        }

        return $response;
    }
}
