<?php

namespace App\Http\Controllers;

use App\Enums\Platform;
use App\Http\Requests\Onboarding\ConfirmSuggestionsRequest;
use App\Http\Requests\Onboarding\StoreBrandProfileRequest;
use App\Models\BrandProfile;
use App\Models\TrackedAccount;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class OnboardingController extends Controller
{
    public function show(Request $request): Response|RedirectResponse
    {
        if ($request->user()->brandProfile()->exists()) {
            return redirect()->route('feed.index');
        }

        return Inertia::render('onboarding/Index', [
            'brand' => null,
            'suggestions' => [],
            'platforms' => collect(Platform::cases())->map(fn (Platform $platform) => $platform->value)->values(),
        ]);
    }

    public function store(StoreBrandProfileRequest $request): RedirectResponse
    {
        $this->upsertBrandProfile($request->user()->id, $request->validated());

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Brand profile saved. Add competitors to start tracking.'),
        ]);

        return redirect()->route('competitors.index');
    }

    public function suggest(StoreBrandProfileRequest $request): Response
    {
        $validated = $request->validated();
        $suggestions = $this->buildSuggestions($validated);

        return Inertia::render('onboarding/Index', [
            'brand' => $validated,
            'suggestions' => $suggestions,
            'platforms' => collect(Platform::cases())->map(fn (Platform $platform) => $platform->value)->values(),
        ]);
    }

    public function confirm(ConfirmSuggestionsRequest $request): RedirectResponse
    {
        $user = $request->user();
        $validated = $request->validated();

        $this->upsertBrandProfile($user->id, $validated);

        foreach ($validated['suggestions'] as $suggestion) {
            $handle = ltrim($suggestion['handle'], '@');

            TrackedAccount::query()->updateOrCreate(
                [
                    'user_id' => $user->id,
                    'platform' => $suggestion['platform'],
                    'handle' => $handle,
                ],
                [
                    'url' => $suggestion['url'] ?? $this->defaultUrl($suggestion['platform'], $handle),
                    'display_name' => $suggestion['display_name'] ?? $handle,
                    'avatar' => $suggestion['avatar'] ?? null,
                ],
            );
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Onboarding complete. Competitors are ready to sync.'),
        ]);

        return redirect()->route('competitors.index');
    }

    /**
     * @param  array{name: string, website?: string|null, description: string, own_handles?: array<string, string|null>|null}  $data
     */
    private function upsertBrandProfile(int $userId, array $data): BrandProfile
    {
        return BrandProfile::query()->updateOrCreate(
            ['user_id' => $userId],
            [
                'name' => $data['name'],
                'website' => $data['website'] ?? null,
                'description' => $data['description'],
                'own_handles' => $data['own_handles'] ?? [],
            ],
        );
    }

    /**
     * Placeholder competitor suggestions until Apify search is wired.
     *
     * @param  array{name: string, description: string}  $brand
     * @return list<array{platform: string, handle: string, url: string, display_name: string, avatar: string|null}>
     */
    private function buildSuggestions(array $brand): array
    {
        $slug = str($brand['name'])->slug('_')->limit(20, '')->toString() ?: 'brand';
        $niche = str($brand['description'])->explode(' ')->take(2)->implode('');

        $candidates = [
            ['platform' => Platform::Instagram->value, 'handle' => "{$slug}_local", 'display_name' => "{$brand['name']} Local"],
            ['platform' => Platform::TikTok->value, 'handle' => "{$slug}tips", 'display_name' => "{$brand['name']} Tips"],
            ['platform' => Platform::Instagram->value, 'handle' => strtolower($niche ?: 'rival').'_co', 'display_name' => ucfirst($niche ?: 'Rival').' Co'],
            ['platform' => Platform::Facebook->value, 'handle' => "{$slug}.hq", 'display_name' => "{$brand['name']} HQ"],
            ['platform' => Platform::LinkedIn->value, 'handle' => "{$slug}-studio", 'display_name' => "{$brand['name']} Studio"],
            ['platform' => Platform::Pinterest->value, 'handle' => "{$slug}boards", 'display_name' => "{$brand['name']} Boards"],
        ];

        return collect($candidates)
            ->take(6)
            ->map(fn (array $row) => [
                'platform' => $row['platform'],
                'handle' => $row['handle'],
                'url' => $this->defaultUrl($row['platform'], $row['handle']),
                'display_name' => $row['display_name'],
                'avatar' => null,
            ])
            ->values()
            ->all();
    }

    private function defaultUrl(string $platform, string $handle): string
    {
        return match ($platform) {
            Platform::Instagram->value => "https://instagram.com/{$handle}",
            Platform::TikTok->value => "https://tiktok.com/@{$handle}",
            Platform::Facebook->value => "https://facebook.com/{$handle}",
            Platform::LinkedIn->value => "https://linkedin.com/company/{$handle}",
            Platform::Pinterest->value => "https://pinterest.com/{$handle}",
            default => "https://{$platform}.com/{$handle}",
        };
    }
}
