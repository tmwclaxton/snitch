<?php

namespace App\Mcp\Tools;

use App\Jobs\GenerateDailyBriefJob;
use App\Mcp\Support\McpAppUrls;
use App\Mcp\Support\McpAuth;
use App\Services\Brief\DailyBriefGenerator;
use App\Support\DailyBriefPresenter;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('get_daily_brief')]
#[Description('Read the daily executive summary at /today: headline, numbers, today\'s actions, competitor moves, and things to watch. Omit date for today in Europe/London. Curator tokens may pass user_id. Read-only; does not regenerate.')]
#[IsReadOnly]
class GetDailyBriefTool extends Tool
{
    public function handle(Request $request, DailyBriefGenerator $generator, DailyBriefPresenter $presenter): Response
    {
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

        $brief = $generator->briefForDate($user, $date);
        $payload = $brief === null ? null : $presenter->payload($brief);

        return Response::json([
            'user_id' => (int) $user->id,
            'date' => $date->toDateString(),
            'brief' => $payload,
            'history' => $generator->history($user),
            'generating' => GenerateDailyBriefJob::isActiveFor($user->id, $date->toDateString()),
            'app_url' => McpAppUrls::today($date->toDateString()),
            'next_step' => $payload === null
                ? 'No daily summary for this date yet. It is created automatically (free) after the 07:25 Europe/London run. Do not force a regenerate.'
                : 'Use the actions today. Mark them done on /today when finished.',
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
