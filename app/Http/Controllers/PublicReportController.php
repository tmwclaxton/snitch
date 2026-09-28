<?php

namespace App\Http\Controllers;

use App\Models\ReportShareLink;
use Illuminate\Http\Response;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class PublicReportController extends Controller
{
    public function show(string $token): InertiaResponse|Response
    {
        $link = ReportShareLink::query()
            ->where('token', $token)
            ->whereNull('revoked_at')
            ->with('monthlyReport')
            ->first();

        if ($link === null || $link->monthlyReport === null) {
            abort(404);
        }

        $payload = is_array($link->monthlyReport->payload) ? $link->monthlyReport->payload : [];

        return Inertia::render('growth/PublicReport', [
            'report' => $payload,
            'token' => $token,
        ]);
    }
}
