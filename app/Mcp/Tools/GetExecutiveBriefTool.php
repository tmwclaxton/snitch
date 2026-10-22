<?php

namespace App\Mcp\Tools;

use App\Mcp\Support\McpAuth;
use App\Services\Brief\DailyBriefGenerator;
use App\Services\Brief\ExecutiveBriefPresenter;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('get_executive_brief')]
#[Description('Read the Executive Brief memo (summary, own activity, competitor activity, grounded suggestions, freshness). Omit date for today in Europe/London. Curator tokens may pass user_id. Read-only.')]
#[IsReadOnly]
class GetExecutiveBriefTool extends Tool
{
    public function handle(
        Request $request,
        DailyBriefGenerator $generator,
        ExecutiveBriefPresenter $presenter,
    ): Response {
        $actor = McpAuth::user($request);
        if ($actor instanceof Response) {
            return $actor;
        }

        $data = $request->validate([
            'date' => ['nullable', 'date_format:Y-m-d'],
            'user_id' => ['nullable', 'integer', 'min:1'],
        ]);

        $user = McpAuth::subjectUser($actor, isset($data['user_id']) ? (int) $data['user_id'] : null);
        if ($user instanceof Response) {
            return $user;
        }

        if ($blocked = McpAuth::requireProductAccess($user, $actor)) {
            return $blocked;
        }

        $date = isset($data['date'])
            ? CarbonImmutable::parse($data['date'], 'Europe/London')->startOfDay()
            : $generator->briefDate();

        $brief = $presenter->forUser($user, $date);

        return Response::json([
            'user_id' => (int) $user->id,
            'date' => $date->toDateString(),
            'brief' => $brief,
            'next_step' => ($brief['empty'] ?? true)
                ? 'No Executive Brief yet. Add competitors, wait for the morning run, or ask the curator to set summary/suggestions once a ready brief exists.'
                : 'Use get_executive_brief for the memo. Curators may call set_executive_brief to override summary or suggestions.',
        ]);
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'date' => $schema->string()
                ->description('Brief date as Y-m-d in Europe/London. Omit for today.')
                ->nullable(),
            'user_id' => $schema->integer()
                ->description('Curator only: another user id.')
                ->nullable(),
        ];
    }
}
