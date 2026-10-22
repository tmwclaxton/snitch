<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\OmitsProductDataWhenPaywalled;
use App\Services\Dashboard\Panels\DashboardPanelService;
use App\Services\Features\FeatureSuggestionService;
use App\Services\Tracking\TrackedByService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    use OmitsProductDataWhenPaywalled;

    public function __invoke(
        Request $request,
        DashboardPanelService $panels,
        TrackedByService $trackedBy,
        FeatureSuggestionService $featureSuggestions,
    ): Response {
        $user = $request->user();

        if ($this->productAccessBlocked($user)) {
            return Inertia::render('Dashboard', [
                'panels' => [],
                'panels_source' => 'empty',
                'trackedBy' => null,
                'featureSuggestions' => [],
            ]);
        }

        $resolved = $panels->forDashboard($user);

        return Inertia::render('Dashboard', [
            'panels' => $resolved['panels'],
            'panels_source' => $resolved['source'],
            'trackedBy' => $trackedBy->forUser($user),
            'featureSuggestions' => $featureSuggestions->forDashboard($user),
        ]);
    }
}
