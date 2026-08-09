<?php

namespace App\Mcp\Tools;

use App\Enums\TrackedAccountKind;
use App\Mcp\Support\McpAuth;
use App\Models\TrackedAccount;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('remove_competitor')]
#[Description('Remove a tracked competitor account.')]
class RemoveCompetitorTool extends Tool
{
    public function handle(Request $request): Response
    {
        $user = McpAuth::user($request);
        if ($user instanceof Response) {
            return $user;
        }

        $data = $request->validate([
            'tracked_account_id' => ['required', 'integer'],
        ]);

        $account = TrackedAccount::query()
            ->where('user_id', $user->id)
            ->where('kind', TrackedAccountKind::Competitor)
            ->whereKey($data['tracked_account_id'])
            ->first();

        if ($account === null) {
            return Response::error('Competitor not found.');
        }

        $account->delete();

        return Response::json(['deleted' => true, 'tracked_account_id' => $data['tracked_account_id']]);
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'tracked_account_id' => $schema->integer()->required(),
        ];
    }
}
