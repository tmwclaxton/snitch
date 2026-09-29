<?php

namespace App\Mcp\Tools;

use App\Jobs\GenerateWeeklyBriefJob;
use App\Mcp\Support\McpAppUrls;
use App\Mcp\Support\McpAuth;
use App\Services\Brief\WeeklyBriefGenerator;
use App\Support\WeeklyBriefPresenter;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('get_weekly_brief')]
#[Description('Read the This week brief at /brief: post ideas, best times, and recent weeks. Briefs generate automatically and free after sync and analysis. Omit week for the current Monday. Does not regenerate.')]
class GetWeeklyBriefTool extends Tool
{
    public function handle(Request $request, WeeklyBriefGenerator $generator, WeeklyBriefPresenter $presenter): Response
    {
        $user = McpAuth::user($request);
        if ($user instanceof Response) {
            return $user;
        }

        if ($blocked = McpAuth::requireProductAccess($user)) {
            return $blocked;
        }

        $data = $request->validate([
            'week' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $weekStart = isset($data['week'])
            ? CarbonImmutable::parse($data['week'], 'Europe/London')->startOfWeek(CarbonImmutable::MONDAY)
            : $generator->currentWeekStart();

        $brief = $generator->briefForWeek($user, $weekStart);
        $payload = $brief === null ? null : $presenter->payload($brief, $user, $generator);

        return Response::json([
            'week_start' => $weekStart->toDateString(),
            'brief' => $payload,
            'history' => $presenter->history($user),
            'generating' => GenerateWeeklyBriefJob::isActiveFor($user->id),
            'app_url' => McpAppUrls::brief($weekStart->toDateString()),
            'next_step' => $payload === null
                ? 'No brief for this week yet. It is created automatically (free) once competitors, analysed posts, and winners clear the thresholds. Do not force a regenerate.'
                : 'Use the ideas for this week. Call mark_weekly_brief_idea_used when an idea is posted.',
        ]);
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'week' => $schema->string()
                ->description('Monday of the week as Y-m-d. Omit for the current week.')
                ->nullable(),
        ];
    }
}
