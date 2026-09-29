<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\OmitsProductDataWhenPaywalled;
use App\Jobs\GenerateWeeklyBriefJob;
use App\Models\WeeklyBriefIdea;
use App\Services\Brief\WeeklyBriefGenerator;
use App\Support\WeeklyBriefPresenter;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class BriefController extends Controller
{
    use OmitsProductDataWhenPaywalled;

    public function index(Request $request, WeeklyBriefGenerator $generator): Response
    {
        $user = $request->user();
        $weekParam = $request->query('week');
        $weekStart = is_string($weekParam) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $weekParam) === 1
            ? CarbonImmutable::parse($weekParam, 'Europe/London')->startOfWeek(CarbonImmutable::MONDAY)
            : $generator->currentWeekStart();

        if ($this->productAccessBlocked($user)) {
            return Inertia::render('brief/Index', [
                'brief' => null,
                'history' => [],
                'weekStart' => $weekStart->toDateString(),
                'creditCost' => WeeklyBriefGenerator::CREDIT_PENCE,
                'generating' => false,
                'canRegenerate' => false,
            ]);
        }

        $brief = $generator->briefForWeek($user, $weekStart);
        $presenter = app(WeeklyBriefPresenter::class);

        return Inertia::render('brief/Index', [
            'brief' => $brief === null ? null : $presenter->payload($brief, $user, $generator),
            'history' => $presenter->history($user),
            'weekStart' => $weekStart->toDateString(),
            'creditCost' => WeeklyBriefGenerator::CREDIT_PENCE,
            'generating' => GenerateWeeklyBriefJob::isActiveFor($user->id),
            'canRegenerate' => $user->isAdmin(),
        ]);
    }

    public function generate(Request $request, WeeklyBriefGenerator $generator): RedirectResponse
    {
        $user = $request->user();

        if (! $user->isAdmin()) {
            abort(403);
        }

        $force = (bool) $request->boolean('force');
        $weekStart = $generator->currentWeekStart();
        $existing = $generator->briefForWeek($user, $weekStart);

        if ($existing !== null && ! $force) {
            return redirect()->route('brief.index');
        }

        if ($force && $existing !== null) {
            GenerateWeeklyBriefJob::queueFor($user->id, force: true, billable: true);
            Inertia::flash('toast', [
                'type' => 'info',
                'message' => 'Regenerating this week\'s brief…',
            ]);

            return back();
        }

        $brief = $generator->generate($user, force: false, billable: false);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'Brief ready.',
        ]);

        return redirect()->route('brief.index');
    }

    public function markUsed(Request $request, WeeklyBriefIdea $idea): RedirectResponse
    {
        $brief = $idea->brief;

        if ($brief === null || (int) $brief->user_id !== (int) $request->user()->id) {
            abort(404);
        }

        $idea->forceFill([
            'used_at' => $idea->used_at === null ? now() : null,
        ])->save();

        return back();
    }
}
