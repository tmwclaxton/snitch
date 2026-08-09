<?php

namespace App\Http\Controllers;

use App\Enums\Platform;
use App\Http\Requests\Competitors\ConfirmSuggestionsRequest;
use App\Http\Requests\Competitors\StoreTrackedAccountRequest;
use App\Jobs\SuggestCompetitorsJob;
use App\Jobs\SyncTrackedAccountJob;
use App\Models\Post;
use App\Models\TrackedAccount;
use App\Models\WinnerInsight;
use App\Services\Billing\PlanEntitlementService;
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
    public function __construct(private PlanEntitlementService $entitlements) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', TrackedAccount::class);

        return Inertia::render('competitors/Index', $this->pageProps($request));
    }

    public function show(Request $request, TrackedAccount $trackedAccount): Response
    {
        $this->authorize('view', $trackedAccount);

        $user = $request->user();
        $inQuota = $this->entitlements->isTrackedAccountInQuota($user, $trackedAccount);

        $trackedAccount->loadCount([
            'posts' => fn ($query) => $query->reelLike(),
        ]);
        $trackedAccount->setAttribute('in_quota', $inQuota);

        $posts = collect();
        $winners = collect();

        if ($inQuota) {
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
        }

        return Inertia::render('competitors/Show', [
            'account' => $trackedAccount,
            'posts' => $posts,
            'winners' => $winners,
            'inQuota' => $inQuota,
        ]);
    }

    public function store(StoreTrackedAccountRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $handle = $data['handle'];
        $platform = Platform::from($data['platform'] instanceof Platform ? $data['platform']->value : $data['platform']);
        $user = $request->user();

        $exists = TrackedAccount::query()
            ->where('user_id', $user->id)
            ->where('platform', $platform)
            ->where('handle', $handle)
            ->exists();

        if (! $exists && ! $this->entitlements->canAddCompetitors($user, 1)) {
            return $this->competitorLimitExceededRedirect();
        }

        $account = TrackedAccount::query()->updateOrCreate(
            [
                'user_id' => $user->id,
                'platform' => $platform,
                'handle' => $handle,
            ],
            [
                'url' => $this->defaultUrl($platform, $handle),
                'display_name' => $data['display_name'] ?? $handle,
            ],
        );

        $account->markSyncRunning();
        SyncTrackedAccountJob::dispatch($account->id);

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

        SuggestCompetitorsJob::clearLatest($userId);

        Cache::put(SuggestCompetitorsJob::cacheKeyFor($userId, $suggestId), [
            'status' => 'pending',
            'suggestions' => null,
            'error' => null,
        ], now()->addHours(2));

        Cache::put(
            SuggestCompetitorsJob::activeCacheKeyFor($userId),
            $suggestId,
            now()->addHours(2),
        );

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
        $netNew = $this->countNetNewSuggestions($user->id, $suggestions);

        if (! $this->entitlements->canAddCompetitors($user, $netNew)) {
            return $this->competitorLimitExceededRedirect();
        }

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
                    'url' => $this->defaultUrl($platform, $handle),
                    'display_name' => $suggestion['display_name'] ?? $handle,
                    'avatar' => $suggestion['avatar'] ?? null,
                ],
            );

            $account->markSyncRunning();
            SyncTrackedAccountJob::dispatch($account->id);
            $confirmed[] = [
                'platform' => $platform->value,
                'handle' => $handle,
            ];
        }

        SuggestCompetitorsJob::pruneLatestSuggestions($user->id, $confirmed);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Competitors added. Sync is starting.'),
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

        return redirect()->route('competitors.index');
    }

    public function sync(Request $request, TrackedAccount $trackedAccount): RedirectResponse
    {
        $this->authorize('update', $trackedAccount);

        if (! $this->entitlements->isTrackedAccountInQuota($request->user(), $trackedAccount)) {
            Inertia::flash('toast', [
                'type' => 'error',
                'message' => __('This competitor is over your plan limit. Remove other accounts or upgrade in Billing to sync again.'),
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

    /**
     * @return array{accounts: \Illuminate\Database\Eloquent\Collection<int, TrackedAccount>, platforms: Collection<int, string>, suggestions: list<array{platform: string, handle: string, url: string, display_name: string, avatar: string|null, source?: string|null}>, suggestRun: array{id: string, status: string}|null, suggestError: string|null}
     */
    private function pageProps(Request $request): array
    {
        $user = $request->user();
        $inQuotaIds = array_fill_keys($this->entitlements->inQuotaTrackedAccountIds($user), true);

        $accounts = $user
            ->trackedAccounts()
            ->withCount('posts')
            ->orderBy('id')
            ->get()
            ->each(function (TrackedAccount $account) use ($inQuotaIds): void {
                $inQuota = isset($inQuotaIds[$account->id]);
                $account->setAttribute('in_quota', $inQuota);
                $account->setAttribute('sync_due', $inQuota && $account->isDueForSync());
                $account->setAttribute(
                    'next_sync_at',
                    $inQuota ? $account->nextSyncAt()?->toIso8601String() : null,
                );
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

    private function competitorLimitExceededRedirect(): RedirectResponse
    {
        Inertia::flash('toast', [
            'type' => 'error',
            'message' => __('You have reached your competitor limit for this plan. Upgrade in Billing to track more.'),
        ]);

        return redirect()->route('competitors.index');
    }

    /**
     * @param  list<array{platform: mixed, handle: string}>  $suggestions
     */
    private function countNetNewSuggestions(int $userId, array $suggestions): int
    {
        $existing = TrackedAccount::query()
            ->where('user_id', $userId)
            ->get(['platform', 'handle'])
            ->mapWithKeys(function (TrackedAccount $account): array {
                $platform = $account->platform instanceof Platform
                    ? $account->platform->value
                    : (string) $account->platform;

                $key = strtolower($platform).':'.strtolower(ltrim((string) $account->handle, '@'));

                return [$key => true];
            })
            ->all();

        $netNew = 0;
        $seen = [];

        foreach ($suggestions as $suggestion) {
            $platform = $suggestion['platform'] instanceof Platform
                ? $suggestion['platform']->value
                : (string) $suggestion['platform'];
            $handle = ltrim(strtolower(trim((string) $suggestion['handle'])), '@');
            $key = strtolower($platform).':'.$handle;

            if (isset($existing[$key]) || isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $netNew++;
        }

        return $netNew;
    }

    /**
     * @return array{id: string, status: string}|null
     */
    private function activeSuggestRun(int $userId): ?array
    {
        $suggestId = Cache::get(SuggestCompetitorsJob::activeCacheKeyFor($userId));

        if (! is_string($suggestId) || ! Str::isUuid($suggestId)) {
            return null;
        }

        $payload = Cache::get(SuggestCompetitorsJob::cacheKeyFor($userId, $suggestId));

        if (! is_array($payload)) {
            SuggestCompetitorsJob::clearActive($userId, $suggestId);

            return null;
        }

        $status = $payload['status'] ?? 'pending';

        if (! in_array($status, ['pending', 'processing'], true)) {
            SuggestCompetitorsJob::clearActive($userId, $suggestId);

            return null;
        }

        return [
            'id' => $suggestId,
            'status' => $status,
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
