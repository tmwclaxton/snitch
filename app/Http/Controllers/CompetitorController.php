<?php

namespace App\Http\Controllers;

use App\Enums\Platform;
use App\Http\Requests\Competitors\StoreTrackedAccountRequest;
use App\Jobs\SyncTrackedAccountJob;
use App\Models\TrackedAccount;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CompetitorController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', TrackedAccount::class);

        $accounts = $request->user()
            ->trackedAccounts()
            ->withCount('posts')
            ->latest()
            ->get();

        return Inertia::render('competitors/Index', [
            'accounts' => $accounts,
            'platforms' => collect(Platform::cases())->map(fn (Platform $p) => $p->value)->values(),
        ]);
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
                'url' => $data['url'] ?? $this->defaultUrl($platform, $handle),
                'display_name' => $data['display_name'] ?? $handle,
            ],
        );

        SyncTrackedAccountJob::dispatch($account->id);

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

    private function defaultUrl(Platform $platform, string $handle): string
    {
        return match ($platform) {
            Platform::Instagram => "https://instagram.com/{$handle}",
            Platform::TikTok => "https://tiktok.com/@{$handle}",
            Platform::Facebook => "https://facebook.com/{$handle}",
            Platform::LinkedIn => "https://linkedin.com/company/{$handle}",
            Platform::Pinterest => "https://pinterest.com/{$handle}",
        };
    }
}
