<?php

namespace App\Http\Controllers;

use App\Enums\Platform;
use App\Enums\TrackedAccountKind;
use App\Http\Requests\Influencers\DecideInfluencerRequest;
use App\Http\Requests\Influencers\GenerateInfluencerBriefRequest;
use App\Http\Requests\Influencers\SearchInfluencersRequest;
use App\Jobs\FindInfluencersJob;
use App\Jobs\SyncTrackedAccountJob;
use App\Models\TrackedAccount;
use App\Services\Billing\PlanEntitlementService;
use App\Services\Influencers\InfluencerDiscoveryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
                'language' => $data['language'] ?? null,
                'min_followers' => $data['min_followers'] ?? null,
                'max_followers' => $data['max_followers'] ?? null,
            ]);
        } catch (RuntimeException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], SymfonyResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        return response()->json(['brief' => $brief]);
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
        $key = "{$platform->value}:{$handle}";

        $run = $this->resolveRunPayload($user->id, $data['run_id'] ?? null);

        if ($run === null) {
            return redirect()->route('influencers.index');
        }

        $suggestion = $this->findSuggestion($run['payload'], $platform->value, $handle);

        if ($suggestion === null) {
            Inertia::flash('toast', [
                'type' => 'error',
                'message' => __('That influencer is not in the current review queue.'),
            ]);

            return redirect()->route('influencers.index');
        }

        $existing = TrackedAccount::query()
            ->where('user_id', $user->id)
            ->where('platform', $platform)
            ->where('handle', $handle)
            ->first();

        // Already tracked as a competitor still counts as kept for review, without
        // converting the row or consuming an influencer slot.
        if ($existing !== null && $existing->kind === TrackedAccountKind::Competitor) {
            $this->markDecision($user->id, $run['id'], $key, 'kept');

            Inertia::flash('toast', [
                'type' => 'success',
                'message' => __('Already tracked as a competitor. Marked as kept.'),
            ]);

            return redirect()->route('influencers.index');
        }

        $needsInfluencerSlot = $existing === null
            || $existing->kind !== TrackedAccountKind::Influencer;

        if ($needsInfluencerSlot && ! $this->entitlements->canAddInfluencers($user, 1)) {
            return $this->influencerLimitExceededRedirect();
        }

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
            ],
        );

        $account->markSyncRunning();
        SyncTrackedAccountJob::dispatch($account->id);

        $this->markDecision($user->id, $run['id'], $key, 'kept');

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Influencer kept. Sync is starting.'),
        ]);

        return redirect()->route('influencers.index');
    }

    public function discard(DecideInfluencerRequest $request): RedirectResponse
    {
        $user = $request->user();
        $data = $request->validated();
        $platform = Platform::from($data['platform']);
        $handle = $data['handle'];
        $key = "{$platform->value}:{$handle}";

        $run = $this->resolveRunPayload($user->id, $data['run_id'] ?? null);

        if ($run === null) {
            return redirect()->route('influencers.index');
        }

        if ($this->findSuggestion($run['payload'], $platform->value, $handle) === null) {
            return redirect()->route('influencers.index');
        }

        $this->markDecision($user->id, $run['id'], $key, 'discarded');

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

        $keptAccounts = TrackedAccount::query()
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
                'posts_count' => $account->posts_count ?? 0,
            ])
            ->values();

        $defaultPlatform = (string) config('snitch.influencer_find.default_platform', 'instagram');
        $runPlatforms = is_array($run['filters']['platforms'] ?? null)
            ? $run['filters']['platforms']
            : [];
        $selectedPlatform = is_string($runPlatforms[0] ?? null) ? $runPlatforms[0] : $defaultPlatform;

        $filters = [
            'platform' => $selectedPlatform,
            'language' => is_array($run['filters'] ?? null)
                ? ($run['filters']['language'] ?? 'English')
                : 'English',
            'min_followers' => is_array($run['filters'] ?? null)
                ? ($run['filters']['min_followers'] ?? null)
                : null,
            'max_followers' => is_array($run['filters'] ?? null)
                ? ($run['filters']['max_followers'] ?? null)
                : null,
            'brief' => is_array($run['filters'] ?? null)
                ? ($run['filters']['brief'] ?? ($run['brief'] ?? ''))
                : '',
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
            'keptAccounts' => $keptAccounts,
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

    private function influencerLimitExceededRedirect(): RedirectResponse
    {
        Inertia::flash('toast', [
            'type' => 'error',
            'message' => __('Influencer limit reached. Upgrade your plan to keep more creators.'),
        ]);

        return redirect()->route('influencers.index');
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
