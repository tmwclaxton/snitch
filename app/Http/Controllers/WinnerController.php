<?php

namespace App\Http\Controllers;

use App\Jobs\ScoreWinnersJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

class WinnerController extends Controller
{
    public function index(): RedirectResponse
    {
        return redirect()->to(route('dashboard').'#performance');
    }

    public function rescore(Request $request): RedirectResponse
    {
        ScoreWinnersJob::queueFor($request->user()->id);

        Inertia::flash('toast', [
            'type' => 'info',
            'message' => 'Rescoring tear sheet...',
        ]);

        return back();
    }

    public function rescoreStatus(Request $request, string $runId): JsonResponse
    {
        $payload = ScoreWinnersJob::statusFor($request->user()->id, $runId);

        if ($payload === null) {
            return response()->json([
                'status' => 'missing',
                'error' => 'Rescore run not found.',
                'winner_count' => null,
            ]);
        }

        return response()->json($payload);
    }
}
