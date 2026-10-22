<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\OmitsProductDataWhenPaywalled;
use App\Services\Dashboard\Panels\DashboardPanelService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    use OmitsProductDataWhenPaywalled;

    public function __invoke(
        Request $request,
        DashboardPanelService $panels,
    ): Response {
        $user = $request->user();

        if ($this->productAccessBlocked($user)) {
            return Inertia::render('Dashboard', [
                'panels' => [],
                'panels_source' => 'empty',
            ]);
        }

        $resolved = $panels->forDashboard($user);

        return Inertia::render('Dashboard', [
            'panels' => $resolved['panels'],
            'panels_source' => $resolved['source'],
        ]);
    }
}
