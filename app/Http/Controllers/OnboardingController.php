<?php

namespace App\Http\Controllers;

use App\Enums\Platform;
use App\Http\Requests\Onboarding\AutofillFromWebsiteRequest;
use App\Http\Requests\Onboarding\StoreBrandProfileRequest;
use App\Jobs\AutofillBrandFromWebsiteJob;
use App\Models\BrandProfile;
use App\Models\User;
use App\Services\Billing\UsageBillingService;
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
    public function __construct(private UsageBillingService $usage) {}

    public function show(Request $request): Response|RedirectResponse
    {
        $user = $request->user();

        if ($this->shouldSkipOnboarding($user)) {
            return redirect()->route('dashboard');
        }

        $brand = $user->brandProfile;
        $step = $brand === null ? 'brand' : 'paywall';

        return Inertia::render('onboarding/Index', [
            'step' => $step,
            'brand' => $brand === null ? null : [
                'name' => $brand->name,
                'website' => $brand->website,
                'description' => $brand->description,
                'own_handles' => $brand->own_handles ?? [],
            ],
            'platforms' => collect(Platform::cases())->map(fn (Platform $platform) => $platform->value)->values(),
            'trialDays' => $this->usage->trialDays(),
            'platformFeePence' => (int) config('billing.platform_fee_pence', 1900),
            'subscribed' => $this->usage->hasPlatformSubscription($user),
        ]);
    }

    public function store(StoreBrandProfileRequest $request): RedirectResponse
    {
        $user = $request->user();

        BrandProfile::query()->updateOrCreate(
            ['user_id' => $user->id],
            [
                'name' => $request->validated('name'),
                'website' => $request->validated('website'),
                'description' => $request->validated('description'),
                'own_handles' => $request->validated('own_handles') ?? [],
            ],
        );

        if ($this->usage->hasOperatorBypass($user) || $this->usage->hasPlatformSubscription($user)) {
            Inertia::flash('toast', [
                'type' => 'success',
                'message' => __('Brand profile saved. Add snitches to start tracking.'),
            ]);

            return redirect()->route('competitors.index');
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Brand profile saved.'),
        ]);

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
        if (! $user->brandProfile()->exists()) {
            return false;
        }

        if ($this->usage->hasOperatorBypass($user)) {
            return true;
        }

        return $this->usage->hasPlatformSubscription($user);
    }
}
