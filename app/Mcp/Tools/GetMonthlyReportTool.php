<?php

namespace App\Mcp\Tools;

use App\Mcp\Support\McpAppUrls;
use App\Mcp\Support\McpAuth;
use App\Models\ReportShareLink;
use App\Services\Growth\MonthlyReportBuilder;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('get_monthly_report')]
#[Description('Read the monthly growth report (same payload as /growth/report). Omit month for the current month so far. Includes an active share URL when one exists.')]
class GetMonthlyReportTool extends Tool
{
    public function handle(Request $request, MonthlyReportBuilder $builder): Response
    {
        $user = McpAuth::user($request);
        if ($user instanceof Response) {
            return $user;
        }

        if ($blocked = McpAuth::requireProductAccess($user)) {
            return $blocked;
        }

        $data = $request->validate([
            'month' => ['nullable', 'date_format:Y-m'],
        ]);

        $monthStart = $builder->monthStart($data['month'] ?? null);
        $report = $builder->persist($user, $monthStart);
        $activeShare = ReportShareLink::query()
            ->where('monthly_report_id', $report->id)
            ->whereNull('revoked_at')
            ->latest('id')
            ->first();

        $month = $monthStart->format('Y-m');

        return Response::json([
            'report' => [
                'id' => $report->id,
                ...(is_array($report->payload) ? $report->payload : []),
            ],
            'share_url' => $activeShare === null
                ? null
                : route('reports.public', ['token' => $activeShare->token], absolute: true),
            'month' => $month,
            'months' => $builder->monthOptions($user, $monthStart),
            'app_url' => McpAppUrls::monthlyReport($month),
            'next_step' => $activeShare === null
                ? 'Call share_monthly_report to mint a public link, or keep the report private.'
                : 'share_url is live. Call revoke_monthly_report to turn it off.',
        ]);
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'month' => $schema->string()
                ->description('Calendar month as Y-m. Omit for the current month.')
                ->nullable(),
        ];
    }
}
