<?php

namespace App\Http\Middleware;

use App\Services\Billing\UsageBillingService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Block MCP tools/call for product data when the user is paywalled.
 * Billing / auth / checkout tools stay available so agents can upgrade.
 */
class EnsureMcpProductAccess
{
    /**
     * @var list<string>
     */
    public const ALLOWED_TOOLS = [
        'workflow_guide',
        'whoami',
        'claim_info',
        'rotate_token',
        'billing_status',
        'create_platform_checkout',
        'create_credit_checkout',
        'billing_portal',
    ];

    public function __construct(private UsageBillingService $usage) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || $this->usage->canAccessProduct($user)) {
            return $next($request);
        }

        $payload = $request->all();
        $method = is_string($payload['method'] ?? null) ? $payload['method'] : null;

        if ($method !== 'tools/call') {
            return $next($request);
        }

        $params = is_array($payload['params'] ?? null) ? $payload['params'] : [];
        $tool = is_string($params['name'] ?? null) ? $params['name'] : null;

        if ($tool !== null && in_array($tool, self::ALLOWED_TOOLS, true)) {
            return $next($request);
        }

        $paywall = $this->usage->paywallState($user);
        $message = $paywall['message'] ?? 'Subscribe to a paid plan to continue using Snitch MCP.';

        return response()->json([
            'jsonrpc' => '2.0',
            'id' => $payload['id'] ?? null,
            'error' => [
                'code' => -32001,
                'message' => $message,
                'data' => [
                    'paywall' => $paywall,
                ],
            ],
        ], 402);
    }
}
