<?php

namespace App\Mcp\Tools;

use App\Services\Billing\AccountClaimService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('create_account')]
#[Description('Create a Snitch agent account. Returns an API token and claim URL. No free usage until a human claims the account and subscribes to the platform plan.')]
class CreateAccountTool extends Tool
{
    public function handle(Request $request, AccountClaimService $claims): Response
    {
        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:120'],
            'email' => ['nullable', 'email', 'max:255', 'unique:users,email'],
        ]);

        $created = $claims->createAgentAccount(
            name: $data['name'] ?? null,
            email: $data['email'] ?? null,
        );

        return Response::json([
            'user_id' => $created['user']->id,
            'email' => $created['user']->email,
            'api_token' => $created['plain_text_token'],
            'claim_url' => $created['claim_url'],
            'mcp_url' => url('/mcp'),
            'note' => 'Use Authorization: Bearer <api_token> against /mcp. Subscribe and top up credits before billable tools. Claim grants £5 once.',
        ]);
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->description('Optional display name')->nullable(),
            'email' => $schema->string()->description('Optional email for claim matching')->nullable(),
        ];
    }
}
