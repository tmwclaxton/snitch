<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\OmitsProductDataWhenPaywalled;
use App\Jobs\ScoreWinnersJob;
use App\Models\TrackedAccount;
use App\Models\User;
use App\Models\WinnerInsight;
use App\Services\Billing\PlanEntitlementService;
use App\Services\Winners\WinnerScorer;
use App\Support\PlatformEmbed;
use App\Support\PostAccountPresenter;
use App\Support\SafeMarkdown;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class WinnerController extends Controller
{
    use OmitsProductDataWhenPaywalled;

    public function __construct(private PlanEntitlementService $entitlements) {}

    public function index(Request $request, WinnerScorer $scorer): Response
    {
        $user = $request->user();
        $presets = config('snitch.winners.presets');
        $accountId = $request->integer('account') ?: null;

        if ($this->productAccessBlocked($user)) {
            $balanced = is_array($presets['balanced'] ?? null) ? $presets['balanced'] : [];

            return Inertia::render('winners/Index', [
                'winners' => [],
                'accounts' => [],
                'filters' => ['account' => null],
                'rule' => [
                    'preset' => 'balanced',
                    'min_multiplier' => (float) ($balanced['min_multiplier'] ?? 2),
                    'min_engagement_rate' => (int) ($balanced['min_engagement_rate'] ?? 0),
                    'min_views' => (int) ($balanced['min_views'] ?? 0),
                    'min_likes' => (int) ($balanced['min_likes'] ?? 0),
                    'recency_days' => (int) ($balanced['recency_days'] ?? 30),
                    'weights' => is_array($balanced['weights'] ?? null) ? $balanced['weights'] : [],
                    'advanced' => ['require_hook' => true, 'require_sfx' => false, 'min_score' => 0],
                ],
                'presets' => $presets,
                'rescoreRun' => null,
            ]);
        }

        return Inertia::render('winners/Index', [
            'winners' => Inertia::defer(fn () => $this->winnersFor($user, $accountId)),
            'accounts' => $this->accountOptions($user),
            'filters' => ['account' => $accountId],
            'rule' => $scorer->ruleFor($user),
            'presets' => $presets,
            'rescoreRun' => ScoreWinnersJob::activeRunFor($user->id),
        ]);
    }

    /**
     * @return list<array{id: int, handle: string, is_own_account: bool}>
     */
    private function accountOptions(User $user): array
    {
        $inQuotaIds = $this->entitlements->inQuotaTrackedAccountIds($user);

        if ($inQuotaIds === []) {
            return [];
        }

        return TrackedAccount::query()
            ->whereIn('id', $inQuotaIds)
            ->orderByDesc('is_own_account')
            ->orderBy('handle')
            ->get(['id', 'handle', 'is_own_account'])
            ->map(fn (TrackedAccount $account): array => [
                'id' => (int) $account->id,
                'handle' => (string) $account->handle,
                'is_own_account' => (bool) $account->is_own_account,
            ])
            ->all();
    }

    /**
     * @return Collection<int, WinnerInsight>
     */
    private function winnersFor(User $user, ?int $accountId = null): Collection
    {
        $inQuotaIds = $this->entitlements->inQuotaTrackedAccountIds($user);
        $tracked = $inQuotaIds === []
            ? collect()
            : TrackedAccount::query()->whereIn('id', $inQuotaIds)->get();

        if ($accountId !== null) {
            $tracked = $tracked->where('id', $accountId)->values();
        }

        $socialIds = $tracked
            ->pluck('social_account_id')
            ->filter()
            ->map(fn (mixed $id): int => (int) $id)
            ->values()
            ->all();

        $winners = WinnerInsight::query()
            ->where('user_id', $user->id)
            ->whereHas('post', function ($query) use ($socialIds): void {
                if ($socialIds === []) {
                    $query->whereRaw('0 = 1');

                    return;
                }

                $query->whereIn('social_account_id', $socialIds);
            })
            ->with(['post.socialAccount', 'post.analysis'])
            ->orderByDesc('performance_multiplier')
            ->orderByDesc('score')
            ->get();

        PostAccountPresenter::attachForUser($winners->pluck('post')->filter(), $user);
        $winners->each(function (WinnerInsight $winner): void {
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

        return $winners;
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
