<?php

namespace App\Mcp\Tools;

use App\Mcp\Support\McpAuth;
use App\Models\MonthlyReport;
use App\Models\ReportShareLink;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('revoke_monthly_report')]
#[Description('Revoke the live public share link for a monthly report. Pass report_id from get_monthly_report.')]
class RevokeMonthlyReportTool extends Tool
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
            'report_id' => ['required', 'integer'],
        ]);

        $report = MonthlyReport::query()
            ->where('user_id', $user->id)
            ->whereKey($data['report_id'])
            ->first();

        if ($report === null) {
            return Response::error('Monthly report not found.');
        }

        $revoked = ReportShareLink::query()
            ->where('monthly_report_id', $report->id)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);

        return Response::json([
            'report_id' => $report->id,
            'revoked' => $revoked > 0,
        ]);
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'report_id' => $schema->integer()
                ->description('Report id from get_monthly_report')
                ->required(),
        ];
    }
}
