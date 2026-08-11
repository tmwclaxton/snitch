<?php

namespace App\Http\Controllers;

use App\Enums\Platform;
use App\Enums\TrackedAccountKind;
use App\Http\Requests\Influencers\BatchDecideInfluencersRequest;
use App\Http\Requests\Influencers\BatchDestroyInfluencersRequest;
use App\Http\Requests\Influencers\DecideInfluencerRequest;
use App\Http\Requests\Influencers\GenerateInfluencerBriefRequest;
use App\Http\Requests\Influencers\SearchInfluencersRequest;
use App\Http\Requests\Influencers\UpdateInfluencerBriefRequest;
use App\Jobs\FindInfluencersJob;
use App\Jobs\SyncTrackedAccountJob;
use App\Models\TrackedAccount;
use App\Models\User;
use App\Services\Billing\PlanEntitlementService;
use App\Services\Billing\UsageBillingService;
use App\Services\Influencers\InfluencerDiscoveryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class InfluencerController extends Controller
{
    public function __construct(
        private PlanEntitlementService $entitlements,
        private InfluencerDiscoveryService $discovery,
        private UsageBillingService $billing,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', TrackedAccount::class);

        return Inertia::render('influencers/Index', $this->pageProps($request));
    }

    public function generateBrief(GenerateInfluencerBriefRequest $request): JsonResponse
    {
        $brand = $request->user()->brandProfile;
        abort_unless($brand !== null, 404);

        $data = $request->validated();

        try {
            $brief = $this->discovery->generateBrief($brand, [
                'platforms' => [$data['platform']],
                'language' => $data['language'] ?? (string) config('snitch.influencer_find.default_language', 'English'),
                'min_followers' => $data['min_followers'] ?? (int) config('snitch.influencer_find.default_min_followers', 1000),
                'max_followers' => $data['max_followers'] ?? (int) config('snitch.influencer_find.default_max_followers', 50000),
            ]);
        } catch (RuntimeException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], SymfonyResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        $brand->forceFill([
            'influencer_brief' => $brief,
        ])->save();

        return response()->json(['brief' => $brief]);
    }

    public function updateBrief(UpdateInfluencerBriefRequest $request): JsonResponse
    {
        $brand = $request->user()->brandProfile;
        abort_unless($brand !== null, 404);

        $brief = trim((string) ($request->validated('influencer_brief') ?? ''));

        $brand->forceFill([
            'influencer_brief' => $brief !== '' ? $brief : null,
        ])->save();

        return response()->json([
            'brief' => $brand->influencer_brief ?? '',
        ]);
    }

    public function search(SearchInfluencersRequest $request): JsonResponse
    {
        $user = $request->user();
        $brand = $user->brandProfile;
        abort_unless($brand !== null, 404);

        if (! $this->canStartSearch($user->id)) {
            return response()->json([
                'message' => 'Finish reviewing every suggestion (Keep or Discard) before starting a new search.',
            ], SymfonyResponse::HTTP_CONFLICT);
        }

        $data = $request->validated();
        $runId = (string) Str::uuid();
        $filters = [
            'platforms' => [$data['platform']],
            'language' => $data['language'] ?? null,
            'min_followers' => $data['min_followers'] ?? null,
            'max_followers' => $data['max_followers'] ?? null,
            'brief' => trim($data['brief']),
        ];

        Cache::put(FindInfluencersJob::cacheKeyFor($user->id, $runId), [
            'status' => 'pending',
            'filters' => $filters,
            'brief' => $filters['brief'],
            'suggestions' => [],
            'decisions' => [],
            'error' => null,
        ], now()->addHours(2));

        Cache::put(
            FindInfluencersJob::activeCacheKeyFor($user->id),
            $runId,
            now()->addHours(2),
        );

        FindInfluencersJob::dispatch($user->id, $runId, $filters);

        return response()->json([
            'id' => $runId,
            'status' => 'pending',
        ], SymfonyResponse::HTTP_ACCEPTED);
    }

    public function searchStatus(Request $request, string $runId): JsonResponse
    {
        $this->authorize('viewAny', TrackedAccount::class);

        if (! Str::isUuid($runId)) {
            abort(404);
        }

        $payload = Cache::get(FindInfluencersJob::cacheKeyFor($request->user()->id, $runId));

        if (! is_array($payload)) {
            return response()->json([
                'id' => $runId,
                'status' => 'missing',
                'suggestions' => null,
                'decisions' => null,
                'error' => 'Influencer search not found or expired.',
            ], SymfonyResponse::HTTP_NOT_FOUND);
        }

        return response()->json([
            'id' => $runId,
            'status' => $payload['status'] ?? 'pending',
            'filters' => $payload['filters'] ?? null,
            'brief' => $payload['brief'] ?? null,
            'suggestions' => $payload['suggestions'] ?? [],
            'decisions' => $payload['decisions'] ?? [],
            'error' => $payload['error'] ?? null,
            'review_complete' => $this->reviewComplete($payload),
        ]);
    }

    public function keep(DecideInfluencerRequest $request): RedirectResponse
    {
        $user = $request->user();
        $data = $request->validated();
        $platform = Platform::from($data['platform']);
        $handle = $data['handle'];

        $run = $this->resolveRunPayload($user->id, $data['run_id'] ?? null);

        if ($run === null) {
            return redirect()->route('influencers.index');
        }

        $result = $this->keepSuggestion($user, $run['id'], $run['payload'], $platform, $handle);

        if ($result === 'missing') {
            Inertia::flash('toast', [
                'type' => 'error',
                'message' => __('That influencer is not in the current review queue.'),
            ]);

            return redirect()->route('influencers.index');
        }

        if ($result === 'competitor') {
            Inertia::flash('toast', [
                'type' => 'success',
                'message' => __('Already tracked as a competitor. Marked as kept.'),
            ]);

            return redirect()->route('influencers.index');
        }

        $canSync = $this->billing->canRun($user);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $canSync
                ? __('Influencer kept. Sync is starting.')
                : __('Influencer kept. Sync needs a balance above :min p - subscribe or top up on Billing.', [
                    'min' => $this->billing->minRunBalancePence(),
                ]),
        ]);

        return redirect()->route('influencers.index');
    }

    public function discard(DecideInfluencerRequest $request): RedirectResponse
    {
        $user = $request->user();
        $data = $request->validated();
        $platform = Platform::from($data['platform']);
        $handle = $data['handle'];

        $run = $this->resolveRunPayload($user->id, $data['run_id'] ?? null);

        if ($run === null) {
            return redirect()->route('influencers.index');
        }

        $this->discardSuggestion($user->id, $run['id'], $run['payload'], $platform, $handle);

        return redirect()->route('influencers.index');
    }

    public function keepMany(BatchDecideInfluencersRequest $request): RedirectResponse
    {
        $user = $request->user();
        $data = $request->validated();
        $run = $this->resolveRunPayload($user->id, $data['run_id'] ?? null);

        if ($run === null) {
            return redirect()->route('influencers.index');
        }

        $kept = 0;
        $canSync = $this->billing->canRun($user);

        foreach ($data['suggestions'] as $row) {
            $platform = Platform::from($row['platform']);
            $handle = $row['handle'];
            $result = $this->keepSuggestion($user, $run['id'], $run['payload'], $platform, $handle);

            if ($result === 'kept' || $result === 'competitor') {
                $kept++;
            }
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $canSync
                ? __('Kept :count influencers. Sync is starting.', ['count' => $kept])
                : __('Kept :count influencers. Sync needs a balance above :min p - subscribe or top up on Billing.', [
                    'count' => $kept,
                    'min' => $this->billing->minRunBalancePence(),
                ]),
        ]);

        return redirect()->route('influencers.index');
    }

    public function discardMany(BatchDecideInfluencersRequest $request): RedirectResponse
    {
        $user = $request->user();
        $data = $request->validated();
        $run = $this->resolveRunPayload($user->id, $data['run_id'] ?? null);

        if ($run === null) {
            return redirect()->route('influencers.index');
        }

        $discarded = 0;

        foreach ($data['suggestions'] as $row) {
            $platform = Platform::from($row['platform']);
            $handle = $row['handle'];

            if ($this->discardSuggestion($user->id, $run['id'], $run['payload'], $platform, $handle)) {
                $discarded++;
            }
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Discarded :count suggestions.', ['count' => $discarded]),
        ]);

        return redirect()->route('influencers.index');
    }

    public function batchDestroy(BatchDestroyInfluencersRequest $request): RedirectResponse
    {
        $ids = $request->validated('ids');
        $user = $request->user();

        $accounts = TrackedAccount::query()
            ->where('user_id', $user->id)
            ->influencers()
            ->whereIn('id', $ids)
            ->get();

        foreach ($accounts as $account) {
            $this->authorize('delete', $account);
            $account->delete();
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Removed :count influencers.', ['count' => $accounts->count()]),
        ]);

        return redirect()->route('influencers.index');
    }

    /**
     * @return array<string, mixed>
     */
    private function pageProps(Request $request): array
    {
        $user = $request->user();
        $brand = $user->brandProfile;
        $active = $this->activeRun($user->id);
        $latest = FindInfluencersJob::latestPayload($user->id);
        $run = $active ?? $latest;

        $suggestions = is_array($run['suggestions'] ?? null) ? $run['suggestions'] : [];
        $decisions = is_array($run['decisions'] ?? null) ? $run['decisions'] : [];

        $reviewQueue = [];

        foreach ($suggestions as $suggestion) {
            if (! is_array($suggestion)) {
                continue;
            }

            $platform = (string) ($suggestion['platform'] ?? '');
            $handle = ltrim((string) ($suggestion['handle'] ?? ''), '@');
            $key = "{$platform}:{$handle}";

            if (! isset($decisions[$key])) {
                $reviewQueue[] = $suggestion;
            }
        }

        $defaultPlatform = (string) config('snitch.influencer_find.default_platform', 'instagram');
        $defaultMinFollowers = (int) config('snitch.influencer_find.default_min_followers', 1000);
        $defaultMaxFollowers = (int) config('snitch.influencer_find.default_max_followers', 50000);
        $runPlatforms = is_array($run['filters']['platforms'] ?? null)
            ? $run['filters']['platforms']
            : [];
        $selectedPlatform = is_string($runPlatforms[0] ?? null) ? $runPlatforms[0] : $defaultPlatform;

        $rawLanguage = is_array($run['filters'] ?? null)
            ? ($run['filters']['language'] ?? null)
            : null;
        $rawMinFollowers = is_array($run['filters'] ?? null)
            ? ($run['filters']['min_followers'] ?? null)
            : null;
        $rawMaxFollowers = is_array($run['filters'] ?? null)
            ? ($run['filters']['max_followers'] ?? null)
            : null;
        $runBrief = is_array($run['filters'] ?? null)
            ? trim((string) ($run['filters']['brief'] ?? ($run['brief'] ?? '')))
            : '';
        $brandBrief = trim((string) ($brand?->influencer_brief ?? ''));

        $filters = [
            'platform' => $selectedPlatform,
            'language' => $this->normalizeLanguageFilter($rawLanguage),
            'min_followers' => is_numeric($rawMinFollowers) ? (int) $rawMinFollowers : $defaultMinFollowers,
            'max_followers' => is_numeric($rawMaxFollowers) ? (int) $rawMaxFollowers : $defaultMaxFollowers,
            'brief' => $runBrief !== '' ? $runBrief : $brandBrief,
        ];

        $summary = $this->entitlements->summary($user);

        return [
            'brand' => $brand === null ? null : [
                'name' => $brand->name,
                'description' => $brand->description,
            ],
            'platforms' => array_map(fn (Platform $platform): string => $platform->value, Platform::cases()),
            'filters' => $filters,
            'searchRun' => $active === null ? null : [
                'id' => $active['id'],
                'status' => $active['status'] ?? 'processing',
            ],
            'latestRun' => $run === null ? null : [
                'id' => $run['id'] ?? null,
                'status' => $run['status'] ?? null,
                'brief' => $run['brief'] ?? ($filters['brief'] ?? ''),
                'error' => $run['error'] ?? null,
                'review_complete' => $this->reviewComplete($run),
            ],
            'suggestions' => $suggestions,
            'decisions' => $decisions,
            'reviewQueue' => $reviewQueue,
            'keptAccounts' => Inertia::defer(fn () => $this->keptAccountsFor($user)),
            'canSearch' => $this->canStartSearch($user->id),
            'influencerCap' => [
                'plan' => $summary['plan'],
                'plan_name' => $summary['plan_name'],
                'influencer_limit' => $summary['influencer_limit'],
                'influencers_used' => $summary['influencers_used'],
                'influencers_remaining' => $summary['influencers_remaining'],
                'can_upgrade' => $summary['can_upgrade'],
            ],
        ];
    }

    /**
     * Load and shape the current user's kept influencer accounts for the Inertia payload.
     *
     * Deferred from the initial page render so the shell + review queue paint immediately
     * while the withCount query and mapping stream in on the follow-up partial reload.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function keptAccountsFor(User $user): Collection
    {
        return TrackedAccount::query()
            ->where('user_id', $user->id)
            ->influencers()
            ->withCount(['posts' => fn ($query) => $query->reelLike()])
            ->orderByDesc('id')
            ->get()
            ->map(fn (TrackedAccount $account): array => [
                'id' => $account->id,
                'platform' => $account->platform->value,
                'handle' => $account->handle,
                'display_name' => $account->display_name,
                'avatar' => $account->avatar,
                'url' => $account->url,
                'fit_reason' => $account->fit_reason,
                'posts_count' => $account->posts_count ?? 0,
            ])
            ->values();
    }

    /**
     * Map stored / free-text language values onto the influencers UI select options.
     * Defaults to the configured language so the Language control always has a real selection.
     */
    private function normalizeLanguageFilter(mixed $language): string
    {
        $default = (string) config('snitch.influencer_find.default_language', 'English');

        if (! is_string($language) || trim($language) === '') {
            return $default;
        }

        $normalized = strtolower(trim($language));

        return match ($normalized) {
            'english', 'en', 'eng', 'en-gb', 'en-us', 'en_gb', 'en_us' => 'English',
            'spanish', 'es', 'spa', 'es-es', 'es_es' => 'Spanish',
            'french', 'fr', 'fre', 'fra', 'fr-fr', 'fr_fr' => 'French',
            'german', 'de', 'ger', 'deu', 'de-de', 'de_de' => 'German',
            'any' => 'any',
            default => $default,
        };
    }

    private function canStartSearch(int $userId): bool
    {
        if ($this->activeRun($userId) !== null) {
            return false;
        }

        $latest = FindInfluencersJob::latestPayload($userId);

        if ($latest === null) {
            return true;
        }

        // Failed runs (including thin partials under min_suggestions) may be re-run
        // without finishing Keep/Discard on every partial card.
        if (($latest['status'] ?? null) === 'failed') {
            return true;
        }

        $suggestions = is_array($latest['suggestions'] ?? null) ? $latest['suggestions'] : [];

        if ($suggestions === []) {
            return true;
        }

        return $this->reviewComplete($latest);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function reviewComplete(array $payload): bool
    {
        $suggestions = is_array($payload['suggestions'] ?? null) ? $payload['suggestions'] : [];
        $decisions = is_array($payload['decisions'] ?? null) ? $payload['decisions'] : [];

        if ($suggestions === []) {
            return true;
        }

        foreach ($suggestions as $suggestion) {
            if (! is_array($suggestion)) {
                continue;
            }

            $platform = (string) ($suggestion['platform'] ?? '');
            $handle = ltrim((string) ($suggestion['handle'] ?? ''), '@');
            $key = "{$platform}:{$handle}";

            if (! isset($decisions[$key])) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function activeRun(int $userId): ?array
    {
        $payload = FindInfluencersJob::activePayload($userId);

        if ($payload === null) {
            return null;
        }

        $status = $payload['status'] ?? null;

        if (in_array($status, ['completed', 'failed'], true)) {
            FindInfluencersJob::clearActive($userId, $payload['id'] ?? null);

            return null;
        }

        return $payload;
    }

    /**
     * @return array{id: string, payload: array<string, mixed>}|null
     */
    private function resolveRunPayload(int $userId, ?string $runId): ?array
    {
        if (is_string($runId) && Str::isUuid($runId)) {
            $payload = Cache::get(FindInfluencersJob::cacheKeyFor($userId, $runId));

            if (is_array($payload)) {
                return ['id' => $runId, 'payload' => $payload];
            }
        }

        $latest = FindInfluencersJob::latestPayload($userId);

        if ($latest === null || ! isset($latest['id'])) {
            return null;
        }

        return ['id' => (string) $latest['id'], 'payload' => $latest];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>|null
     */
    private function findSuggestion(array $payload, string $platform, string $handle): ?array
    {
        $suggestions = is_array($payload['suggestions'] ?? null) ? $payload['suggestions'] : [];

        foreach ($suggestions as $suggestion) {
            if (! is_array($suggestion)) {
                continue;
            }

            if (
                ($suggestion['platform'] ?? null) === $platform
                && ltrim((string) ($suggestion['handle'] ?? ''), '@') === $handle
            ) {
                return $suggestion;
            }
        }

        return null;
    }

    private function markDecision(int $userId, string $runId, string $key, string $decision): void
    {
        $payload = Cache::get(FindInfluencersJob::cacheKeyFor($userId, $runId));

        if (! is_array($payload)) {
            return;
        }

        $decisions = is_array($payload['decisions'] ?? null) ? $payload['decisions'] : [];
        $decisions[$key] = $decision;
        $payload['decisions'] = $decisions;

        Cache::put(FindInfluencersJob::cacheKeyFor($userId, $runId), $payload, now()->addHours(2));
        Cache::put(FindInfluencersJob::latestCacheKeyFor($userId), $runId, now()->addHours(24));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return 'kept'|'competitor'|'missing'
     */
    private function keepSuggestion(
        User $user,
        string $runId,
        array $payload,
        Platform $platform,
        string $handle,
    ): string {
        $key = "{$platform->value}:{$handle}";
        $suggestion = $this->findSuggestion($payload, $platform->value, $handle);

        if ($suggestion === null) {
            return 'missing';
        }

        $existing = TrackedAccount::query()
            ->where('user_id', $user->id)
            ->where('platform', $platform)
            ->where('handle', $handle)
            ->first();

        // Already tracked as a competitor still counts as kept for review, without
        // converting the row or consuming an influencer slot.
        if ($existing !== null && $existing->kind === TrackedAccountKind::Competitor) {
            $this->markDecision($user->id, $runId, $key, 'kept');

            return 'competitor';
        }

        $fitReason = isset($suggestion['fit_reason']) && is_string($suggestion['fit_reason'])
            ? trim($suggestion['fit_reason'])
            : '';

        $account = TrackedAccount::query()->updateOrCreate(
            [
                'user_id' => $user->id,
                'platform' => $platform,
                'handle' => $handle,
            ],
            [
                'kind' => TrackedAccountKind::Influencer,
                'url' => (string) ($suggestion['url'] ?? $this->defaultUrl($platform, $handle)),
                'display_name' => $suggestion['display_name'] ?? $handle,
                'avatar' => $suggestion['avatar'] ?? null,
                'fit_reason' => $fitReason !== '' ? Str::limit($fitReason, 280, '') : null,
            ],
        );

        if ($this->billing->canRun($user)) {
            $account->markSyncRunning();
            SyncTrackedAccountJob::dispatch($account->id);
        }

        $this->markDecision($user->id, $runId, $key, 'kept');

        return 'kept';
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function discardSuggestion(
        int $userId,
        string $runId,
        array $payload,
        Platform $platform,
        string $handle,
    ): bool {
        if ($this->findSuggestion($payload, $platform->value, $handle) === null) {
            return false;
        }

        $this->markDecision($userId, $runId, "{$platform->value}:{$handle}", 'discarded');

        return true;
    }

    private function defaultUrl(Platform $platform, string $handle): string
    {
        return match ($platform) {
            Platform::Instagram => "https://www.instagram.com/{$handle}/",
            Platform::TikTok => "https://www.tiktok.com/@{$handle}",
            Platform::Youtube => "https://www.youtube.com/@{$handle}",
            Platform::LinkedIn => "https://www.linkedin.com/in/{$handle}",
            Platform::Facebook => "https://www.facebook.com/{$handle}",
        };
    }
}
