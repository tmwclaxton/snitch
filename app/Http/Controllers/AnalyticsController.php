<?php

namespace App\Http\Controllers;

use App\Http\Requests\AnalyticsPeriodRequest;
use App\Services\SnitchAnalyticsService;
use Illuminate\Http\JsonResponse;
use Inertia\Inertia;
use Inertia\Response;

class AnalyticsController extends Controller
{
    public function __construct(
        private readonly SnitchAnalyticsService $analytics,
    ) {}

    public function index(AnalyticsPeriodRequest $request): Response
    {
        return Inertia::render('marketing/Analytics', [
            'analytics' => $this->analytics->publicSummary($request->dateRange()),
        ]);
    }

    /**
     * Public JSON for badges and other read-only consumers.
     */
    public function json(AnalyticsPeriodRequest $request): JsonResponse
    {
        return response()
            ->json($this->analytics->publicSummary($request->dateRange()))
            ->header('Cache-Control', 'public, max-age=300');
    }
}
