<?php

namespace App\Http\Controllers;

use App\Jobs\ScoreWinnersJob;
use App\Models\WinnerInsight;
use App\Services\Winners\WinnerScorer;
use App\Support\PlatformEmbed;
use App\Support\SafeMarkdown;
use Illuminate\Http\JsonResponse;
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
            ->get()
            ->each(function (WinnerInsight $winner): void {
                $winner->setAttribute(
                    'how_to_copy_html',
                    SafeMarkdown::toHtml($winner->how_to_copy),
                );

                $post = $winner->post;

                if ($post === null) {
                    return;
                }

                $post->setAttribute(
                    'embed',
                    PlatformEmbed::resolve($post->platform, $post->url, compact: true),
                );
            });

        return Inertia::render('winners/Index', [
            'winners' => $winners,
            'rule' => $rule,
            'presets' => config('snitch.winners.presets'),
            'rescoreRun' => ScoreWinnersJob::activeRunFor($user->id),
        ]);
    }

    public function rescore(Request $request): RedirectResponse
    {
        ScoreWinnersJob::queueFor($request->user()->id);

        Inertia::flash('toast', [
            'type' => 'info',
            'message' => 'Rescoring tear sheet…',
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
