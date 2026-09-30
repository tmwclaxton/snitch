<?php

namespace App\Http\Controllers;

use App\Enums\Platform;
use App\Enums\TrackedAccountKind;
use App\Http\Requests\Onboarding\AutofillFromWebsiteRequest;
use App\Http\Requests\Onboarding\StoreOnboardingCompetitorsRequest;
use App\Http\Requests\Onboarding\StoreOwnHandleRequest;
use App\Jobs\AutofillBrandFromWebsiteJob;
use App\Models\BrandProfile;
use App\Models\TrackedAccount;
use App\Models\User;
use App\Services\Billing\PlanEntitlementService;
use App\Services\Billing\UsageBillingService;
use App\Services\Onboarding\OnboardingAccountSearchService;
use App\Services\Tracking\TrackedByService;
use App\Support\SocialHandle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class OnboardingController extends Controller
{
    public function __construct(
        private OnboardingAccountSearchService $accounts,
        private TrackedByService $trackedBy,
        private UsageBillingService $usage,
        private PlanEntitlementService $entitlements,
    ) {}

    public function show(Request $request): Response|RedirectResponse
    {
        $user = $request->user();

        if ($this->shouldSkipOnboarding($user)) {
            return redirect()->route('dashboard');
        }

        $brand = $user->brandProfile;
        $ownHandle = is_array($brand?->own_handles)
            ? ($brand->own_handles['instagram'] ?? null)
            : null;
        $competitors = $user->trackedAccounts()
            ->competitors()
            ->orderBy('id')
            ->get(['id', 'platform', 'handle', 'display_name', 'avatar', 'followers'])
            ->map(fn (TrackedAccount $account): array => [
                'id' => $account->id,
                'platform' => $account->platform instanceof Platform
                    ? $account->platform->value
                    : (string) $account->platform,
                'handle' => $account->handle,
                'display_name' => $account->display_name,
                'avatar' => $account->avatar,
                'followers' => $account->followers,
            ])
            ->values()
            ->all();

        $step = 'competitors';

        if ($request->query('step') === 'paywall' && $competitors !== []) {
            $step = 'paywall';
        } elseif ($request->query('step') === 'reveal' && $competitors !== []) {
            $step = 'reveal';
        } elseif ($competitors !== [] && filled($ownHandle) && $request->query('step') !== 'competitors') {
            $step = 'reveal';
        }

        return Inertia::render('onboarding/Index', [
            'step' => $step,
            'ownHandle' => $ownHandle ? ltrim((string) $ownHandle, '@') : null,
            'competitors' => $competitors,
            'trackedBy' => $this->trackedBy->forUser($user),
            'trialDays' => $this->usage->trialDays(),
            'trialCompetitorLimit' => (int) config('subscriptions.trial_competitor_limit', 3),
            'platformFeePence' => (int) config('billing.platform_fee_pence', 1900),
            'subscribed' => $this->usage->hasPlatformSubscription($user),
            'platforms' => collect(Platform::cases())->map(fn (Platform $platform) => $platform->value)->values(),
        ]);
    }

    public function search(Request $request): JsonResponse
    {
        $data = $request->validate([
            'q' => ['required', 'string', 'min:2', 'max:120'],
        ]);

        return response()->json([
            'results' => $this->accounts->search($data['q'], $request->user()),
        ]);
    }

    public function lookup(Request $request): JsonResponse
    {
        $data = $request->validate([
            'q' => ['required', 'string', 'min:2', 'max:255'],
            'platform' => ['nullable', 'string'],
        ]);

        $platform = null;
        if (! empty($data['platform'])) {
            $platform = Platform::tryFrom((string) $data['platform']);
        }

        $result = $this->accounts->lookup($data['q'], $platform);

        if ($result === null) {
            return response()->json([
                'result' => null,
                'error' => 'Could not find that account. Check the handle or profile URL.',
            ], SymfonyResponse::HTTP_NOT_FOUND);
        }

        return response()->json(['result' => $result]);
    }

    public function saveOwnHandle(StoreOwnHandleRequest $request): RedirectResponse
    {
        $user = $request->user();
        $handle = SocialHandle::normalize($request->validated('handle'));

        BrandProfile::query()->updateOrCreate(
            ['user_id' => $user->id],
            [
                'name' => $user->brandProfile?->name ?: ($user->name ?: 'My brand'),
                'website' => $user->brandProfile?->website,
                'description' => $user->brandProfile?->description ?: 'Brand profile',
                'own_handles' => array_merge(
                    is_array($user->brandProfile?->own_handles) ? $user->brandProfile->own_handles : [],
                    ['instagram' => '@'.$handle],
                ),
            ],
        );

        return redirect()->back();
    }

    public function store(StoreOnboardingCompetitorsRequest $request): RedirectResponse
    {
        $user = $request->user();
        $rows = $request->validated('competitors');

        BrandProfile::query()->updateOrCreate(
            ['user_id' => $user->id],
            [
                'name' => $user->brandProfile?->name ?: ($user->name ?: 'My brand'),
                'website' => $user->brandProfile?->website,
                'description' => $user->brandProfile?->description ?: 'Brand profile',
                'own_handles' => $user->brandProfile?->own_handles ?? [],
            ],
        );

        $ownHandle = $request->validated('own_handle');
        if (filled($ownHandle)) {
            $normalizedOwn = SocialHandle::normalize($ownHandle);
            if ($normalizedOwn !== null) {
                $brand = $user->fresh()->brandProfile;
                $brand?->forceFill([
                    'own_handles' => array_merge(
                        is_array($brand->own_handles) ? $brand->own_handles : [],
                        ['instagram' => '@'.$normalizedOwn],
                    ),
                ])->save();
            }
        }

        foreach ($rows as $row) {
            if (! $this->entitlements->canAddCompetitors($user, 1)) {
                break;
            }

            $platform = Platform::from($row['platform']);
            $handle = SocialHandle::normalize($row['handle']);

            if ($handle === null || SocialHandle::isWeak($handle, $platform)) {
                continue;
            }

            TrackedAccount::updateOrRestore(
                [
                    'user_id' => $user->id,
                    'platform' => $platform,
                    'handle' => $handle,
                ],
                [
                    'kind' => TrackedAccountKind::Competitor,
                    'url' => $row['url'] ?? $this->defaultUrl($platform, $handle),
                    'display_name' => $row['display_name'] ?? $handle,
                    'avatar' => $row['avatar'] ?? null,
                    'avatar_source_url' => isset($row['avatar']) && is_string($row['avatar'])
                        && str_starts_with($row['avatar'], 'http')
                            ? $row['avatar']
                            : null,
                    'followers' => isset($row['followers']) && is_numeric($row['followers'])
                        ? (int) $row['followers']
                        : null,
                    'is_own_account' => false,
                ],
            );
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Competitors saved.'),
        ]);

        return redirect()->route('onboarding.show', ['step' => 'reveal']);
    }

    public function continueToPaywall(Request $request): RedirectResponse
    {
        $user = $request->user();

        if ($user->trackedAccounts()->competitors()->doesntExist()) {
            return redirect()->route('onboarding.show');
        }

        if ($this->usage->hasPlatformSubscription($user) || $this->usage->hasOperatorBypass($user)) {
            return redirect()->route('dashboard');
        }

        return redirect()->route('onboarding.show', ['step' => 'paywall']);
    }

    public function startAutofill(AutofillFromWebsiteRequest $request): JsonResponse
    {
        $website = $request->validated('website');
        $autofillId = (string) Str::uuid();
        $userId = $request->user()->id;

        Cache::put(AutofillBrandFromWebsiteJob::cacheKeyFor($userId, $autofillId), [
            'status' => 'pending',
            'website' => $website,
            'fields' => null,
            'error' => null,
        ], now()->addMinutes(15));

        AutofillBrandFromWebsiteJob::dispatch($userId, $autofillId, $website);

        return response()->json([
            'id' => $autofillId,
            'status' => 'pending',
        ], SymfonyResponse::HTTP_ACCEPTED);
    }

    public function autofillStatus(Request $request, string $autofillId): JsonResponse
    {
        if (! Str::isUuid($autofillId)) {
            abort(404);
        }

        $payload = Cache::get(AutofillBrandFromWebsiteJob::cacheKeyFor($request->user()->id, $autofillId));

        if (! is_array($payload)) {
            return response()->json([
                'id' => $autofillId,
                'status' => 'missing',
                'fields' => null,
                'error' => 'Autofill job not found or expired.',
            ], SymfonyResponse::HTTP_NOT_FOUND);
        }

        return response()->json([
            'id' => $autofillId,
            'status' => $payload['status'] ?? 'pending',
            'fields' => $payload['fields'] ?? null,
            'error' => $payload['error'] ?? null,
            'website' => $payload['website'] ?? null,
        ]);
    }

    private function shouldSkipOnboarding(User $user): bool
    {
        if ($user->trackedAccounts()->competitors()->doesntExist()) {
            return false;
        }

        if ($this->usage->hasOperatorBypass($user)) {
            return true;
        }

        return $this->usage->hasPlatformSubscription($user);
    }

    private function defaultUrl(Platform $platform, string $handle): string
    {
        return match ($platform) {
            Platform::Instagram => 'https://www.instagram.com/'.$handle.'/',
            Platform::TikTok => 'https://www.tiktok.com/@'.$handle,
            Platform::Youtube => 'https://www.youtube.com/@'.$handle,
            Platform::LinkedIn => 'https://www.linkedin.com/company/'.$handle,
            Platform::Facebook => 'https://www.facebook.com/'.$handle,
        };
    }
}
