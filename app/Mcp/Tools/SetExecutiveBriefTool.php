<?php

namespace App\Mcp\Tools;

use App\Mcp\Support\McpAuth;
use App\Services\Brief\ExecutiveBriefCurator;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('set_executive_brief')]
#[Description('Curator only. Override the Executive Brief summary and/or suggestions for a user (scoped curator key, audited). Pass summary and/or suggestions; null clears that override. Does not touch billing, auth, trackers, scrapes, or panel rows.')]
class SetExecutiveBriefTool extends Tool
{
    public function handle(Request $request, ExecutiveBriefCurator $curator): Response
    {
        $actor = McpAuth::user($request);
        if ($actor instanceof Response) {
            return $actor;
        }

        if ($blocked = McpAuth::requireCurator($actor)) {
            return $blocked;
        }

        $data = $request->validate([
            'user_id' => ['required', 'integer', 'min:1'],
            'date' => ['nullable', 'date_format:Y-m-d'],
            'summary' => ['nullable', 'string', 'max:2000'],
            'suggestions' => ['nullable', 'array', 'max:5'],
            'suggestions.*.title' => ['required_with:suggestions', 'string', 'max:500'],
            'suggestions.*.why' => ['required_with:suggestions', 'string', 'max:2000'],
            'suggestions.*.related_post_ids' => ['nullable', 'array', 'max:10'],
            'suggestions.*.related_post_ids.*' => ['integer', 'min:1'],
        ]);

        $subject = McpAuth::subjectUser($actor, (int) $data['user_id']);
        if ($subject instanceof Response) {
            return $subject;
        }

        if (! array_key_exists('summary', $data) && ! array_key_exists('suggestions', $data)) {
            return Response::error('Provide summary and/or suggestions.');
        }

        $date = isset($data['date'])
            ? CarbonImmutable::parse($data['date'], 'Europe/London')->startOfDay()
            : null;

        $input = [];
        if (array_key_exists('summary', $data)) {
            $input['summary'] = $data['summary'];
        }
        if (array_key_exists('suggestions', $data)) {
            $input['suggestions'] = $data['suggestions'];
        }

        try {
            $brief = $curator->setForUser($subject, $actor, $input, $date);
        } catch (ValidationException $e) {
            return Response::error(collect($e->errors())->flatten()->implode(' '));
        }

        return Response::json([
            'user_id' => (int) $subject->id,
            'brief' => $brief,
        ]);
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'user_id' => $schema->integer()->description('Target user id.')->required(),
            'date' => $schema->string()
                ->description('Brief date as Y-m-d. Omit for the latest ready brief / today.')
                ->nullable(),
            'summary' => $schema->string()
                ->description('2 to 3 sentence summary override. Null clears the curator summary.')
                ->nullable(),
            'suggestions' => $schema->array()
                ->description('Up to 5 suggestions with title, why, optional related_post_ids. Null clears overrides.')
                ->nullable(),
        ];
    }
}
