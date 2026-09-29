<?php

namespace App\Mcp\Tools;

use App\Mcp\Support\McpAuth;
use App\Models\WeeklyBriefIdea;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('mark_weekly_brief_idea_used')]
#[Description('Toggle a weekly brief idea as posted or not. Pass idea_id from get_weekly_brief. Same behaviour as the Used control on /brief.')]
class MarkWeeklyBriefIdeaUsedTool extends Tool
{
    public function handle(Request $request): Response
    {
        $user = McpAuth::user($request);
        if ($user instanceof Response) {
            return $user;
        }

        if ($blocked = McpAuth::requireProductAccess($user)) {
            return $blocked;
        }

        $data = $request->validate([
            'idea_id' => ['required', 'integer'],
        ]);

        $idea = WeeklyBriefIdea::query()->with('brief')->find($data['idea_id']);
        $brief = $idea?->brief;

        if ($idea === null || $brief === null || (int) $brief->user_id !== (int) $user->id) {
            return Response::error('Weekly brief idea not found.');
        }

        $idea->forceFill([
            'used_at' => $idea->used_at === null ? now() : null,
        ])->save();

        return Response::json([
            'idea_id' => $idea->id,
            'used_at' => $idea->used_at?->toIso8601String(),
            'used' => $idea->used_at !== null,
        ]);
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'idea_id' => $schema->integer()
                ->description('Idea id from get_weekly_brief.ideas[].id')
                ->required(),
        ];
    }
}
