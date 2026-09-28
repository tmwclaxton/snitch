<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\OmitsProductDataWhenPaywalled;
use App\Models\MonthlyReport;
use App\Models\ReportShareLink;
use App\Services\Growth\MonthlyReportBuilder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Inertia\Inertia;
use Inertia\Response;

class MonthlyReportController extends Controller
{
    use OmitsProductDataWhenPaywalled;

    public function show(Request $request, MonthlyReportBuilder $builder): Response
    {
        $user = $request->user();
        $monthStart = $builder->monthStart($request->query('month'));

        if ($this->productAccessBlocked($user)) {
            return Inertia::render('growth/Report', [
                'report' => null,
                'shareUrl' => null,
                'month' => $monthStart->format('Y-m'),
                'months' => [],
            ]);
        }

        $report = $builder->persist($user, $monthStart);
        $activeShare = ReportShareLink::query()
            ->where('monthly_report_id', $report->id)
            ->whereNull('revoked_at')
            ->latest('id')
            ->first();

        return Inertia::render('growth/Report', [
            'report' => [
                'id' => $report->id,
                ...(is_array($report->payload) ? $report->payload : []),
            ],
            'shareUrl' => $activeShare === null
                ? null
                : route('reports.public', ['token' => $activeShare->token], absolute: true),
            'shareToken' => $activeShare?->token,
            'month' => $monthStart->format('Y-m'),
            'months' => $builder->monthOptions($user, $monthStart),
        ]);
    }

    public function share(Request $request, MonthlyReport $report): RedirectResponse
    {
        if ((int) $report->user_id !== (int) $request->user()->id) {
            abort(404);
        }

        // Revoke prior active links so only one share URL is live.
        ReportShareLink::query()
            ->where('monthly_report_id', $report->id)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);

        $link = ReportShareLink::query()->create([
            'user_id' => $request->user()->id,
            'monthly_report_id' => $report->id,
            'token' => ReportShareLink::mintToken(),
        ]);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'Share link ready.',
        ]);

        return back()->with('share_token', $link->token);
    }

    public function revoke(Request $request, MonthlyReport $report): RedirectResponse
    {
        if ((int) $report->user_id !== (int) $request->user()->id) {
            abort(404);
        }

        ReportShareLink::query()
            ->where('monthly_report_id', $report->id)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);

        Inertia::flash('toast', [
            'type' => 'info',
            'message' => 'Share link revoked.',
        ]);

        return back();
    }

    public function pdf(Request $request, MonthlyReport $report): HttpResponse
    {
        if ((int) $report->user_id !== (int) $request->user()->id) {
            abort(404);
        }

        $payload = is_array($report->payload) ? $report->payload : [];

        return response()
            ->view('reports.monthly-print', [
                'report' => $payload,
                'brand' => config('app.name', 'Snitch'),
            ])
            ->header('Content-Type', 'text/html; charset=UTF-8');
    }
}
