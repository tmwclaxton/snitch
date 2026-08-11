<?php

namespace App\Mcp\Tools;

use App\Mcp\Support\McpAuth;
use App\Mcp\Support\McpRuntime;
use App\Services\Billing\UsageBillingService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('billing_status')]
#[Description('Return credit balance and usage charged by vendor (Apify, NanoGPT, Firecrawl, TikHub), plus runtime warnings (local vs prod, queue workers). Billable tools need balance above 20p.')]
class BillingStatusTool extends Tool
{
    public function handle(Request $request, UsageBillingService $usage): Response
    {
        $user = McpAuth::user($request);
        if ($user instanceof Response) {
            return $user;
        }

        $summary = $usage->summary($user);
        $runtime = McpRuntime::snapshot();
        $paywall = $usage->paywallState($user);

        $nextStep = 'Balance OK for billable tools.';
        if ($paywall['blocked']) {
            $nextStep = $paywall['reason'] === 'subscribe'
                ? 'Free starter used up. Call create_platform_checkout before product tools.'
                : 'Usage allowance spent. Call create_credit_checkout (requires paid plan) before product tools.';
        }

        return Response::json(array_merge($summary, [
            'runtime' => $runtime,
            'can_run_billable' => ! $paywall['blocked'],
            'paywall' => $paywall,
            'next_step' => $nextStep,
        ]));
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
