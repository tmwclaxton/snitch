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

#[Name('get_daily_brief')]
#[Description('Read the daily executive summary at /today: headline, numbers, today\'s actions, competitor moves, and things to watch. Omit date for today in Europe/London. Read-only; does not regenerate.')]
class GetDailyBriefTool extends Tool
{
    public function handle(Request $request, DailyBriefGenerator $generator, DailyBriefPresenter $presenter): Response
    {
        $user = McpAuth::user($request);
        if ($user instanceof Response) {
            return $user;
        }

        if ($blocked = McpAuth::requireProductAccess($user)) {
            return $blocked;
        }

        $data = $request->validate([
            'date' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $date = isset($data['date'])
            ? CarbonImmutable::parse($data['date'], 'Europe/London')->startOfDay()
            : $generator->briefDate();

        $brief = $generator->briefForDate($user, $date);
        $payload = $brief === null ? null : $presenter->payload($brief);

        return Response::json([
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
        ];
    }
}
