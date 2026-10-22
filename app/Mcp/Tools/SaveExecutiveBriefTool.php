<?php

namespace App\Mcp\Tools;

use App\Mcp\Support\McpAuth;
use App\Services\Automations\AutomationRunTokenIssuer;
use App\Services\Automations\SaveExecutiveBriefService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('save_executive_brief')]
#[Description('Automation only. Save a grounded Executive Brief for the token-bound user and run. Pass headline, summary, optional sections, and 3-5 suggestions each with why and evidence_post_ids. Server validates every number against stored facts; on failure the current generated brief is kept and the run is closed.')]
class SaveExecutiveBriefTool extends Tool
{
    public function handle(Request $request, SaveExecutiveBriefService $saver): Response
    {
        $actor = McpAuth::user($request);
        if ($actor instanceof Response) {
            return $actor;
        }

        $auth = McpAuth::requireAutomationAbility($actor, AutomationRunTokenIssuer::ABILITY_BRIEF_WRITE);
        if ($auth instanceof Response) {
            return $auth;
        }

        $run = McpAuth::automationRun($actor);
        if ($run === null) {
            return Response::error('Automation run not found.');
        }

        $data = $request->validate([
            'headline' => ['required', 'string', 'max:500'],
            'summary' => ['required', 'string', 'max:4000'],
            'sections' => ['nullable', 'array', 'max:10'],
            'sections.*.title' => ['nullable', 'string', 'max:120'],
            'sections.*.body' => ['required_with:sections', 'string', 'max:4000'],
            'suggestions' => ['required', 'array', 'min:3', 'max:5'],
            'suggestions.*.title' => ['required', 'string', 'max:500'],
            'suggestions.*.why' => ['required', 'string', 'max:2000'],
            'suggestions.*.how' => ['nullable', 'string', 'max:2000'],
            'suggestions.*.evidence_post_ids' => ['nullable', 'array', 'max:10'],
            'suggestions.*.evidence_post_ids.*' => ['integer', 'min:1'],
            'suggestions.*.related_post_ids' => ['nullable', 'array', 'max:10'],
            'suggestions.*.related_post_ids.*' => ['integer', 'min:1'],
            // Ignored: scope comes only from the token.
            'user_id' => ['nullable', 'integer', 'min:1'],
            'run_id' => ['nullable', 'string', 'max:64'],
        ]);

        try {
            $result = $saver->save($actor, $run, $data);
        } catch (ValidationException $e) {
            return Response::error(collect($e->errors())->flatten()->implode(' '));
        }

        return Response::json([
            'ok' => $result['ok'],
            'kept_existing' => $result['kept_existing'],
            'errors' => $result['errors'],
            'run_id' => $result['run']->uuid,
            'status' => $result['run']->status,
            'user_id' => (int) $actor->id,
            'brief' => $result['brief'],
        ]);
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'headline' => $schema->string()->description('Yellow-block headline.')->required(),
            'summary' => $schema->string()->description('2-3 sentence interpretive summary.')->required(),
            'sections' => $schema->array()
                ->description('Optional memo sections (title + body), e.g. own / competitors.')
                ->nullable(),
            'suggestions' => $schema->array()
                ->description('3-5 suggestions with title, why, and evidence_post_ids.')
                ->required(),
            'user_id' => $schema->integer()
                ->description('Ignored. Scope is the token owner only.')
                ->nullable(),
            'run_id' => $schema->string()
                ->description('Optional echo of the payload run id; the token binding wins.')
                ->nullable(),
        ];
    }
}
