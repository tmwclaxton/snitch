<?php

namespace App\Mcp\Tools;

use App\Mcp\Support\McpAuth;
use App\Models\MonthlyReport;
use App\Models\ReportShareLink;
use App\Models\User;
use App\Services\Growth\MonthlyReportBuilder;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('share_monthly_report')]
#[Description('Mint a public share URL for a monthly report. Pass report_id from get_monthly_report, or month as Y-m. Replaces any previous live link for that report.')]
class ShareMonthlyReportTool extends Tool
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
            'report_id' => ['nullable', 'integer'],
            'month' => ['nullable', 'date_format:Y-m'],
        ]);

        $report = $this->resolveReport($user->id, $data, $builder);

        if ($report === null) {
            return Response::error('Monthly report not found. Call get_monthly_report first.');
        }

        ReportShareLink::query()
            ->where('monthly_report_id', $report->id)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);

        $link = ReportShareLink::query()->create([
            'user_id' => $user->id,
            'monthly_report_id' => $report->id,
            'token' => ReportShareLink::mintToken(),
        ]);

        return Response::json([
            'report_id' => $report->id,
            'share_url' => route('reports.public', ['token' => $link->token], absolute: true),
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function resolveReport(int $userId, array $data, MonthlyReportBuilder $builder): ?MonthlyReport
    {
        if (isset($data['report_id'])) {
            return MonthlyReport::query()
                ->where('user_id', $userId)
                ->whereKey($data['report_id'])
                ->first();
        }

        if (! isset($data['month'])) {
            return null;
        }

        $user = User::query()->find($userId);

        if ($user === null) {
            return null;
        }

        return $builder->persist($user, $builder->monthStart($data['month']));
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'report_id' => $schema->integer()
                ->description('Report id from get_monthly_report. Prefer this over month.')
                ->nullable(),
            'month' => $schema->string()
                ->description('Y-m. Used when report_id is omitted. Builds the report if needed.')
                ->nullable(),
        ];
    }
}
