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

    /**
     * Keys sent on the first paint (controls + rail). Everything else loads in
     * one deferred `panel` group so charts and the contact sheet arrive together.
     *
     * @var list<string>
     */
    private const IMMEDIATE_KEYS = [
        'period',
        'periods',
        'timezone',
        'own_account',
        'rivals',
        'selected',
        'max_compare',
        'legacy_non_instagram_count',
        'show_hidden_likes',
        'controls',
        'onboarding',
        'rail',
        'kpis',
    ];

    /**
     * @var list<string>
     */
    private const PANEL_KEYS = [
        'insights',
        'leaderboard',
        'winners',
        'growth_series',
        'efficiency',
        'format_mix',
        'format_lift',
        'heatmap',
        'captions',
        'themes',
        'weekly',
        'attention',
        'actions',
        'data_notes',
        'activity',
        'follower_series',
        'growth_delta',
        'recent_posts',
        'caption_intel',
    ];

    public function __invoke(Request $request, DashboardMetrics $metrics): Response
    {
        $user = $request->user();
        $handles = $this->selectedHandles($request);
        $period = $this->periodDays($request);
        $showHiddenLikes = $this->showHiddenLikes($request);

        if ($this->productAccessBlocked($user)) {
            return Inertia::render('Dashboard', $metrics->emptyPayload());
        }

        // Build once for the first paint (fills cache). Closures on immediate
        // keys would become Inertia optional/lazy props and skip the response.
        $payload = $metrics->forUser($user, $handles, $period, $showHiddenLikes);
        $props = [];

        foreach (self::IMMEDIATE_KEYS as $key) {
            $props[$key] = $payload[$key] ?? null;
        }

        foreach (self::PANEL_KEYS as $key) {
            $props[$key] = Inertia::defer(
                fn () => $metrics->forUser($user, $handles, $period, $showHiddenLikes)[$key] ?? null,
                'panel',
            );
        }

        return Inertia::render('Dashboard', $props);
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

    private function showHiddenLikes(Request $request): bool
    {
        $raw = $request->query('hidden', '0');

        return in_array($raw, [1, '1', true, 'true', 'on'], true);
    }
}
