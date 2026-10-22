<?php

namespace App\Mcp\Tools;

use App\Mcp\Support\McpAuth;
use App\Services\Automations\AutomationRunTokenIssuer;
use App\Services\Automations\PostChatReplyService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('post_chat_reply')]
#[Description('Automation only. Post a chat reply for the token-bound user and run. Call with partial=true to stream growing text; call again with partial=false or done=true for the final answer (closes the run and revokes the token).')]
class PostChatReplyTool extends Tool
{
    public function handle(Request $request, PostChatReplyService $replies): Response
    {
        $actor = McpAuth::user($request);
        if ($actor instanceof Response) {
            return $actor;
        }

        $auth = McpAuth::requireAutomationAbility($actor, AutomationRunTokenIssuer::ABILITY_CHAT_WRITE);
        if ($auth instanceof Response) {
            return $auth;
        }

        $run = McpAuth::automationRun($actor);
        if ($run === null) {
            return Response::error('Automation run not found.');
        }

        $data = $request->validate([
            'text' => ['nullable', 'string', 'max:16000'],
            'partial' => ['nullable', 'boolean'],
            'done' => ['nullable', 'boolean'],
            // Ignored: scope comes only from the token.
            'user_id' => ['nullable', 'integer', 'min:1'],
            'run_id' => ['nullable', 'string', 'max:64'],
        ]);

        try {
            $result = $replies->post(
                $actor,
                $run,
                (string) ($data['text'] ?? ''),
                (bool) ($data['partial'] ?? false),
                (bool) ($data['done'] ?? false),
            );
        } catch (ValidationException $e) {
            return Response::error(collect($e->errors())->flatten()->implode(' '));
        }

        return Response::json([
            'ok' => $result['ok'],
            'partial' => $result['partial'],
            'run_id' => $result['run']->uuid,
            'status' => $result['run']->status,
            'user_id' => (int) $actor->id,
            'reply' => $result['reply'],
        ]);
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'text' => $schema->string()->description('Reply text (full or growing partial).')->nullable(),
            'partial' => $schema->boolean()
                ->description('True to stream a partial update without closing the run.')
                ->nullable(),
            'done' => $schema->boolean()
                ->description('True to finalise the reply, close the run, and revoke the token.')
                ->nullable(),
            'user_id' => $schema->integer()
                ->description('Ignored. Scope is the token owner only.')
                ->nullable(),
            'run_id' => $schema->string()
                ->description('Optional echo of the payload run id; the token binding wins.')
                ->nullable(),
        ];
    }
}
