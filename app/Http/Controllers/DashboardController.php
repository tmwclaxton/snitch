<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\OmitsProductDataWhenPaywalled;
use App\Services\Dashboard\DashboardMetrics;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    use OmitsProductDataWhenPaywalled;

    public function __invoke(Request $request, DashboardMetrics $metrics): Response
    {
        $user = $request->user();
        $handles = $this->selectedHandles($request);
        $period = $this->periodDays($request);

        if ($this->productAccessBlocked($user)) {
            return Inertia::render('Dashboard', $metrics->emptyPayload());
        }

        return Inertia::render('Dashboard', $metrics->forUser($user, $handles, $period));
    }

    /**
     * @return list<string>
     */
    private function selectedHandles(Request $request): array
    {
        $raw = $request->query('accounts', '');

        if (! is_string($raw) || trim($raw) === '') {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn (string $handle): string => strtolower(ltrim(trim($handle), '@')),
            explode(',', $raw),
        )));
    }

    private function periodDays(Request $request): int
    {
        $raw = $request->query('period', 30);
        $period = is_numeric($raw) ? (int) $raw : 30;

        return in_array($period, DashboardMetrics::PERIODS, true) ? $period : 30;
    }
}
