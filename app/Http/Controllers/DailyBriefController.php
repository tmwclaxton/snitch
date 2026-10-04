<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\OmitsProductDataWhenPaywalled;
use App\Jobs\GenerateDailyBriefJob;
use App\Models\DailyBrief;
use App\Services\Brief\DailyBriefGenerator;
use App\Support\DailyBriefPresenter;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DailyBriefController extends Controller
{
    use OmitsProductDataWhenPaywalled;

    public function index(Request $request, DailyBriefGenerator $generator, DailyBriefPresenter $presenter): Response
    {
        $user = $request->user();
        $dateParam = $request->query('date');
        $date = is_string($dateParam) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateParam) === 1
            ? CarbonImmutable::parse($dateParam, 'Europe/London')->startOfDay()
            : $generator->briefDate();

        if ($this->productAccessBlocked($user)) {
            return Inertia::render('today/Index', [
                'brief' => null,
                'history' => [],
                'date' => $date->toDateString(),
                'generating' => false,
                'canRegenerate' => false,
            ]);
        }

        $brief = $generator->briefForDate($user, $date);

        return Inertia::render('today/Index', [
            'brief' => $brief === null ? null : $presenter->payload($brief),
            'history' => $generator->history($user),
            'date' => $date->toDateString(),
            'generating' => GenerateDailyBriefJob::isActiveFor($user->id, $date->toDateString()),
            'canRegenerate' => $user->isAdmin(),
        ]);
    }

    public function generate(Request $request, DailyBriefGenerator $generator): RedirectResponse
    {
        $user = $request->user();

        if (! $user->isAdmin()) {
            abort(403);
        }

        $date = $generator->briefDate();
        GenerateDailyBriefJob::queueFor($user->id, $date->toDateString(), force: true);

        Inertia::flash('toast', [
            'type' => 'info',
            'message' => 'Regenerating today\'s summary.',
        ]);

        return back();
    }

    public function toggleAction(Request $request, DailyBrief $brief, int $index): RedirectResponse
    {
        if ((int) $brief->user_id !== (int) $request->user()->id) {
            abort(404);
        }

        $payload = is_array($brief->payload) ? $brief->payload : [];
        $actions = $payload['actions'] ?? [];

        if (! isset($actions[$index]) || ! is_array($actions[$index])) {
            abort(404);
        }

        $actions[$index]['done_at'] = filled($actions[$index]['done_at'] ?? null)
            ? null
            : now()->toIso8601String();
        $payload['actions'] = $actions;
        $brief->forceFill(['payload' => $payload])->save();

        return back();
    }
}
