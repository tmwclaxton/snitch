<?php

namespace App\Http\Controllers;

use App\Enums\Platform;
use App\Enums\TrackedAccountKind;
use App\Exceptions\InsufficientCreditsException;
use App\Http\Requests\Competitors\ConfirmSuggestionsRequest;
use App\Http\Requests\Competitors\StoreTrackedAccountRequest;
use App\Jobs\SuggestCompetitorsJob;
use App\Jobs\SyncTrackedAccountJob;
use App\Models\Post;
use App\Models\TrackedAccount;
use App\Models\User;
use App\Models\WinnerInsight;
use App\Services\Billing\PlanEntitlementService;
use App\Services\Billing\UsageBillingService;
use App\Support\PlatformEmbed;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class CompetitorController extends Controller
{
    public function __construct(
        private PlanEntitlementService $entitlements,
        private UsageBillingService $billing,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', TrackedAccount::class);

        return Inertia::render('competitors/Index', $this->pageProps($request));
    }

    public function show(Request $request, TrackedAccount $trackedAccount): Response
    {
        $this->authorize('view', $trackedAccount);

        $user = $request->user();

        $trackedAccount->loadCount([
            'posts' => fn ($query) => $query->reelLike(),
        ]);
        $trackedAccount->setAttribute('in_quota', true);

        $posts = Post::query()
            ->where('tracked_account_id', $trackedAccount->id)
            ->where('user_id', $user->id)
            ->reelLike()
            ->with(['trackedAccount', 'analysis', 'winnerInsight'])
            ->latest('posted_at')
            ->limit(24)
            ->get()
            ->map(function (Post $post): Post {
                $post->setAttribute(
                    'embed',
                    PlatformEmbed::resolve($post->platform, $post->url, compact: true),
                );

                return $post;
            });

        $winners = WinnerInsight::query()
            ->where('user_id', $user->id)
            ->whereHas('post', fn ($query) => $query->where('tracked_account_id', $trackedAccount->id))
            ->with(['post.trackedAccount', 'post.analysis'])
            ->orderByDesc('score')
            ->limit(8)
            ->get();

        return Inertia::render('competitors/Show', [
            'account' => $trackedAccount,
            'posts' => $posts,
            'winners' => $winners,
        ]);
    }

    public function store(StoreTrackedAccountRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $handle = $data['handle'];
        $platform = Platform::from($data['platform'] instanceof Platform ? $data['platform']->value : $data['platform']);
        $user = $request->user();

        $account = TrackedAccount::query()->updateOrCreate(
            [
                'user_id' => $user->id,
                'platform' => $platform,
                'handle' => $handle,
            ],
            [
                'kind' => TrackedAccountKind::Competitor,
                'url' => $this->defaultUrl($platform, $handle),
                'display_name' => $data['display_name'] ?? $handle,
            ],
        );

        $this->queueSyncIfBillable($user, $account);

        SuggestCompetitorsJob::pruneLatestSuggestions($user->id, [[
            'platform' => $platform->value,
            'handle' => $handle,
        ]]);

        return redirect()->route('competitors.index');
    }

    public function suggest(Request $request): JsonResponse
    {
        $this->authorize('create', TrackedAccount::class);

        $brand = $request->user()->brandProfile;
        abort_unless($brand !== null, 404);

        $suggestId = (string) Str::uuid();
        $userId = $request->user()->id;

        SuggestCompetitorsJob::beginRun($userId, $suggestId);
        SuggestCompetitorsJob::dispatch($userId, $suggestId);

        return response()->json([
            'id' => $suggestId,
            'status' => 'pending',
        ], SymfonyResponse::HTTP_ACCEPTED);
    }

    public function suggestStatus(Request $request, string $suggestId): JsonResponse
    {
        $this->authorize('create', TrackedAccount::class);

        if (! Str::isUuid($suggestId)) {
            abort(404);
        }

        $payload = Cache::get(SuggestCompetitorsJob::cacheKeyFor($request->user()->id, $suggestId));

        if (! is_array($payload)) {
            return response()->json([
                'id' => $suggestId,
                'status' => 'missing',
                'suggestions' => null,
                'error' => 'Suggestion job not found or expired.',
            ], SymfonyResponse::HTTP_NOT_FOUND);
        }

        return response()->json([
            'id' => $suggestId,
            'status' => $payload['status'] ?? 'pending',
            'suggestions' => $payload['suggestions'] ?? null,
            'error' => $payload['error'] ?? null,
        ]);
    }

    public function confirmSuggestions(ConfirmSuggestionsRequest $request): RedirectResponse
    {
        $user = $request->user();
        $suggestions = $request->validated('suggestions');

        $confirmed = [];

        foreach ($suggestions as $suggestion) {
            $handle = ltrim($suggestion['handle'], '@');
            $platform = Platform::from($suggestion['platform'] instanceof Platform ? $suggestion['platform']->value : $suggestion['platform']);

            $account = TrackedAccount::query()->updateOrCreate(
                [
                    'user_id' => $user->id,
                    'platform' => $platform,
                    'handle' => $handle,
                ],
                [
                    'kind' => TrackedAccountKind::Competitor,
                    'url' => $this->defaultUrl($platform, $handle),
                    'display_name' => $suggestion['display_name'] ?? $handle,
                    'avatar' => $suggestion['avatar'] ?? null,
                ],
            );

            $this->queueSyncIfBillable($user, $account);
            $confirmed[] = [
                'platform' => $platform->value,
                'handle' => $handle,
            ];
        }

        SuggestCompetitorsJob::pruneLatestSuggestions($user->id, $confirmed);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $this->billing->canRun($user)
                ? __('Competitors added. Sync is starting.')
                : __('Competitors added. Sync needs a balance above :min p - subscribe or top up on Billing.', [
                    'min' => $this->billing->minRunBalancePence(),
                ]),
        ]);

        return redirect()->route('competitors.index');
    }

    public function dismissSuggestions(Request $request): RedirectResponse
    {
        $this->authorize('create', TrackedAccount::class);

        SuggestCompetitorsJob::clearLatest($request->user()->id);

        return redirect()->route('competitors.index');
    }

    public function destroy(Request $request, TrackedAccount $trackedAccount): RedirectResponse
    {
        $this->authorize('delete', $trackedAccount);

        $trackedAccount->delete();

        return redirect()->back(fallback: route('competitors.index'));
    }

    public function sync(Request $request, TrackedAccount $trackedAccount): RedirectResponse
    {
        $this->authorize('update', $trackedAccount);

        try {
            $this->billing->assertCanRun($request->user());
        } catch (InsufficientCreditsException $exception) {
            Inertia::flash('toast', [
                'type' => 'error',
                'message' => $exception->getMessage(),
            ]);

            return back();
        }

        $trackedAccount->markSyncRunning();
        SyncTrackedAccountJob::dispatch($trackedAccount->id, force: true);

        Inertia::flash('toast', [
            'type' => 'info',
            'message' => __('Sync running for @:handle. This page updates when it finishes.', [
                'handle' => $trackedAccount->handle,
            ]),
        ]);

        return back();
    }

    private function queueSyncIfBillable(User $user, TrackedAccount $account): void
    {
        if (! $this->billing->canRun($user)) {
            return;
        }

        $account->markSyncRunning();
        SyncTrackedAccountJob::dispatch($account->id);
    }

    /**
     * @return array{accounts: \Illuminate\Database\Eloquent\Collection<int, TrackedAccount>, platforms: Collection<int, string>, suggestions: list<array{platform: string, handle: string, url: string, display_name: string, avatar: string|null, source?: string|null}>, suggestRun: array{id: string, status: string}|null, suggestError: string|null}
     */
    private function pageProps(Request $request): array
    {
        $user = $request->user();

        $accounts = $user
            ->trackedAccounts()
            ->competitors()
            ->withCount('posts')
            ->orderBy('id')
            ->get()
            ->each(function (TrackedAccount $account): void {
                $account->setAttribute('in_quota', true);
            });

        $latest = $this->latestSuggestPayload($request->user()->id);
        $suggestions = is_array($latest['suggestions'] ?? null) ? $latest['suggestions'] : [];
        $trackedKeys = $accounts
            ->map(function (TrackedAccount $account): string {
                $platform = $account->platform instanceof Platform
                    ? $account->platform->value
                    : (string) $account->platform;

                return strtolower($platform).':'.strtolower(ltrim((string) $account->handle, '@'));
            })
            ->all();
        $trackedLookup = array_fill_keys($trackedKeys, true);

        $visibleSuggestions = array_values(array_filter(
            $suggestions,
            function (mixed $row) use ($trackedLookup): bool {
                if (! is_array($row)) {
                    return false;
                }

                $platform = strtolower(trim((string) ($row['platform'] ?? '')));
                $handle = ltrim(strtolower(trim((string) ($row['handle'] ?? ''))), '@');

                if ($platform === '' || $handle === '') {
                    return false;
                }

                return ! isset($trackedLookup["{$platform}:{$handle}"]);
            },
        ));

        // Persist the filtered set so reload / dismiss stay consistent with tracked accounts.
        if ($visibleSuggestions !== $suggestions) {
            SuggestCompetitorsJob::pruneLatestSuggestions(
                $request->user()->id,
                array_values(array_filter(
                    $suggestions,
                    function (mixed $row) use ($trackedLookup): bool {
                        if (! is_array($row)) {
                            return true;
                        }

                        $platform = strtolower(trim((string) ($row['platform'] ?? '')));
                        $handle = ltrim(strtolower(trim((string) ($row['handle'] ?? ''))), '@');

                        return isset($trackedLookup["{$platform}:{$handle}"]);
                    },
                )),
            );
        }

        return [
            'accounts' => $accounts,
            'platforms' => collect(Platform::cases())->map(fn (Platform $p) => $p->value)->values(),
            'suggestions' => $visibleSuggestions,
            'suggestRun' => $this->activeSuggestRun($request->user()->id),
            'suggestError' => is_string($latest['error'] ?? null) ? $latest['error'] : null,
            'competitorCap' => $this->entitlements->summary($request->user()),
        ];
    }

    /**
     * @return array{id: string, status: string}|null
     */
    private function activeSuggestRun(int $userId): ?array
    {
        $suggestId = Cache::get(SuggestCompetitorsJob::activeCacheKeyFor($userId));
        $fromActivePointer = is_string($suggestId) && Str::isUuid($suggestId);

        // Older MCP runs wrote latest without the active pointer; still surface those in the UI.
        if (! $fromActivePointer) {
            $suggestId = Cache::get(SuggestCompetitorsJob::latestCacheKeyFor($userId));
        }

        if (! is_string($suggestId) || ! Str::isUuid($suggestId)) {
            return null;
        }

        $payload = Cache::get(SuggestCompetitorsJob::cacheKeyFor($userId, $suggestId));

        if (! is_array($payload)) {
            if ($fromActivePointer) {
                SuggestCompetitorsJob::clearActive($userId, $suggestId);
            }

            return null;
        }

        $status = $payload['status'] ?? 'pending';

        // queued is a legacy MCP seed status; treat like pending for the web poll UI.
        if (! in_array($status, ['pending', 'processing', 'queued'], true)) {
            if ($fromActivePointer) {
                SuggestCompetitorsJob::clearActive($userId, $suggestId);
            }

            return null;
        }

        return [
            'id' => $suggestId,
            'status' => $status === 'queued' ? 'pending' : $status,
        ];
    }

    /**
     * @return array{status?: string, suggestions?: list<array<string, mixed>>|null, error?: string|null}
     */
    private function latestSuggestPayload(int $userId): array
    {
        $suggestId = Cache::get(SuggestCompetitorsJob::latestCacheKeyFor($userId));

        if (! is_string($suggestId) || ! Str::isUuid($suggestId)) {
            return [];
        }

        $payload = Cache::get(SuggestCompetitorsJob::cacheKeyFor($userId, $suggestId));

        if (! is_array($payload) || ($payload['status'] ?? null) !== 'completed') {
            return [];
        }

        return $payload;
    }

    private function defaultUrl(Platform $platform, string $handle): string
    {
        return match ($platform) {
            Platform::Instagram => "https://instagram.com/{$handle}",
            Platform::TikTok => "https://tiktok.com/@{$handle}",
            Platform::Facebook => "https://facebook.com/{$handle}",
            Platform::LinkedIn => "https://linkedin.com/company/{$handle}",
            Platform::Youtube => "https://youtube.com/@{$handle}",
        };
    }
}
