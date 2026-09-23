<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\OmitsProductDataWhenPaywalled;
use App\Services\Dashboard\LovableDashboardBuilder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    use OmitsProductDataWhenPaywalled;

    public function __invoke(Request $request, LovableDashboardBuilder $builder): Response
    {
        $user = $request->user();
        $handles = $this->selectedHandles($request);

        if ($this->productAccessBlocked($user)) {
            return Inertia::render('Dashboard', $this->emptyPayload());
        }

        return Inertia::render('Dashboard', $builder->forUser($user, $handles));
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

    /**
     * @return array<string, mixed>
     */
    private function emptyPayload(): array
    {
        return [
            'own_account' => null,
            'rivals' => [],
            'selected' => [],
            'max_compare' => LovableDashboardBuilder::MAX_COMPARE,
            'headline' => null,
            'insights' => [
                'bestTimes' => [],
                'formatStats' => [],
                'bestLength' => null,
                'patternLifts' => [],
                'hasAnyData' => false,
            ],
            'gaps' => [],
            'kpis' => [
                'avg_er' => 0,
                'posts' => 0,
                'posts_week' => 0,
                'avg_likes' => 0,
                'avg_comments' => 0,
            ],
            'compare' => [],
            'top_posts' => [],
            'heatmap' => array_fill(0, 7, array_fill(0, 24, 0)),
            'format_split' => [],
            'phrases' => [],
        ];
    }
}
