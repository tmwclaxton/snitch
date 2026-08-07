<?php

namespace App\Http\Controllers;

use App\Jobs\ScoreWinnersJob;
use App\Models\WinnerInsight;
use App\Services\Winners\WinnerScorer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class WinnerController extends Controller
{
    public function index(Request $request, WinnerScorer $scorer): Response
    {
        $user = $request->user();
        $rule = $scorer->ruleFor($user);

        $winners = WinnerInsight::query()
            ->where('user_id', $user->id)
            ->with(['post.trackedAccount', 'post.analysis'])
            ->orderByDesc('score')
            ->get();

        return Inertia::render('winners/Index', [
            'winners' => $winners,
            'rule' => $rule,
            'presets' => config('snitch.winners.presets'),
        ]);
    }

    public function rescore(Request $request): RedirectResponse
    {
        ScoreWinnersJob::dispatch($request->user()->id);

        return back();
    }
}
