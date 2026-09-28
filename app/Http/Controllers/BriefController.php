<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\OmitsProductDataWhenPaywalled;
use App\Jobs\GenerateWeeklyBriefJob;
use App\Models\Post;
use App\Models\User;
use App\Models\WeeklyBrief;
use App\Models\WeeklyBriefIdea;
use App\Services\Brief\WeeklyBriefGenerator;
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
        $history = WeeklyBrief::query()
            ->where('user_id', $user->id)
            ->orderByDesc('week_start')
            ->limit(12)
            ->get(['id', 'week_start', 'generated_at', 'was_free', 'credits_charged_pence'])
            ->map(fn (WeeklyBrief $row): array => [
                'id' => $row->id,
                'week_start' => $row->week_start?->toDateString(),
                'generated_at' => $row->generated_at?->toIso8601String(),
                'was_free' => (bool) $row->was_free,
                'credits_charged_pence' => (float) $row->credits_charged_pence,
            ])
            ->all();

        return Inertia::render('brief/Index', [
            'brief' => $brief === null ? null : $this->briefPayload($brief, $user, $generator),
            'history' => $history,
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

    /**
     * @return array<string, mixed>
     */
    private function briefPayload(WeeklyBrief $brief, User $user, WeeklyBriefGenerator $generator): array
    {
        $postIds = $brief->ideas
            ->flatMap(fn (WeeklyBriefIdea $idea) => $idea->inspired_by_post_ids ?? [])
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();

        $posts = $postIds === []
            ? collect()
            : Post::query()
                ->whereIn('id', $postIds)
                ->with(['socialAccount'])
                ->get()
                ->keyBy('id');

        $winnerLookup = collect($generator->topWinners($user, 30))
            ->keyBy('post_id');

        return [
            'id' => $brief->id,
            'week_start' => $brief->week_start?->toDateString(),
            'status' => $brief->status,
            'best_times' => $brief->best_times ?? [],
            'heat_grid' => $brief->heat_grid ?? [],
            'thin_data' => (bool) $brief->thin_data,
            'was_free' => (bool) $brief->was_free,
            'credits_charged_pence' => (float) $brief->credits_charged_pence,
            'generated_at' => $brief->generated_at?->toIso8601String(),
            'ideas' => $brief->ideas->map(function (WeeklyBriefIdea $idea) use ($posts, $winnerLookup): array {
                $sources = collect($idea->inspired_by_post_ids ?? [])
                    ->map(function ($id) use ($posts, $winnerLookup): ?array {
                        $post = $posts->get((int) $id);

                        if ($post === null) {
                            return null;
                        }

                        $winner = $winnerLookup->get((int) $post->id);

                        return [
                            'id' => (int) $post->id,
                            'url' => $post->url,
                            'thumbnail_url' => $post->cover_url,
                            'handle' => (string) ($winner['handle'] ?? $post->socialAccount?->handle ?? 'unknown'),
                            'pi' => isset($winner['pi']) ? (float) $winner['pi'] : null,
                        ];
                    })
                    ->filter()
                    ->values()
                    ->all();

                return [
                    'id' => $idea->id,
                    'position' => $idea->position,
                    'format' => $idea->format,
                    'hook' => $idea->hook,
                    'caption_angle' => $idea->caption_angle,
                    'cta' => $idea->cta,
                    'hashtags' => $idea->hashtags ?? [],
                    'recommended_day' => $idea->recommended_day,
                    'recommended_hour' => $idea->recommended_hour,
                    'why' => $idea->why,
                    'used_at' => $idea->used_at?->toIso8601String(),
                    'inspired_by' => $sources,
                ];
            })->values()->all(),
        ];
    }
}
