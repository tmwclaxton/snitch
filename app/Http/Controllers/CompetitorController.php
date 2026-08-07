<?php

namespace App\Http\Controllers;

use App\Enums\Platform;
use App\Http\Requests\Competitors\ConfirmSuggestionsRequest;
use App\Http\Requests\Competitors\StoreTrackedAccountRequest;
use App\Jobs\SuggestCompetitorsJob;
use App\Jobs\SyncTrackedAccountJob;
use App\Models\TrackedAccount;
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
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', TrackedAccount::class);

        return Inertia::render('competitors/Index', $this->pageProps($request));
    }

    public function store(StoreTrackedAccountRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $handle = $data['handle'];
        $platform = Platform::from($data['platform'] instanceof Platform ? $data['platform']->value : $data['platform']);

        $account = TrackedAccount::query()->updateOrCreate(
            [
                'user_id' => $request->user()->id,
                'platform' => $platform,
                'handle' => $handle,
            ],
            [
                'url' => $this->defaultUrl($platform, $handle),
                'display_name' => $data['display_name'] ?? $handle,
            ],
        );

        SyncTrackedAccountJob::dispatch($account->id);

        return redirect()->route('competitors.index');
    }

    public function suggest(Request $request): JsonResponse
    {
        $this->authorize('create', TrackedAccount::class);

        $brand = $request->user()->brandProfile;
        abort_unless($brand !== null, 404);

        $suggestId = (string) Str::uuid();
        $userId = $request->user()->id;

        Cache::put(SuggestCompetitorsJob::cacheKeyFor($userId, $suggestId), [
            'status' => 'pending',
            'suggestions' => null,
            'error' => null,
        ], now()->addMinutes(15));

        Cache::put(
            SuggestCompetitorsJob::activeCacheKeyFor($userId),
            $suggestId,
            now()->addMinutes(15),
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
        foreach ($request->validated('suggestions') as $suggestion) {
            $handle = ltrim($suggestion['handle'], '@');
            $platform = Platform::from($suggestion['platform'] instanceof Platform ? $suggestion['platform']->value : $suggestion['platform']);

            $account = TrackedAccount::query()->updateOrCreate(
                [
                    'user_id' => $request->user()->id,
                    'platform' => $platform,
                    'handle' => $handle,
                ],
                [
                    'url' => $this->defaultUrl($platform, $handle),
                    'display_name' => $suggestion['display_name'] ?? $handle,
                    'avatar' => $suggestion['avatar'] ?? null,
                ],
            );

            SyncTrackedAccountJob::dispatch($account->id);
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Competitors added. Sync is starting.'),
        ]);

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

        SyncTrackedAccountJob::dispatch($trackedAccount->id);

        return back();
    }

    /**
     * @return array{accounts: \Illuminate\Database\Eloquent\Collection<int, TrackedAccount>, platforms: Collection<int, string>, suggestions: list<array{platform: string, handle: string, url: string, display_name: string, avatar: string|null}>, suggestRun: array{id: string, status: string}|null}
     */
    private function pageProps(Request $request): array
    {
        $accounts = $request->user()
            ->trackedAccounts()
            ->withCount('posts')
            ->latest()
            ->get();

        return [
            'accounts' => $accounts,
            'platforms' => collect(Platform::cases())->map(fn (Platform $p) => $p->value)->values(),
            'suggestions' => [],
            'suggestRun' => $this->activeSuggestRun($request->user()->id),
        ];
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

    private function defaultUrl(Platform $platform, string $handle): string
    {
        return match ($platform) {
            Platform::Instagram => "https://instagram.com/{$handle}",
            Platform::TikTok => "https://tiktok.com/@{$handle}",
            Platform::Facebook => "https://facebook.com/{$handle}",
            Platform::LinkedIn => "https://linkedin.com/company/{$handle}",
        };
    }
}
