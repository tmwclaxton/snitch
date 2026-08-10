<?php

namespace App\Http\Controllers;

use App\Enums\Platform;
use App\Http\Requests\Onboarding\AutofillFromWebsiteRequest;
use App\Http\Requests\Onboarding\StoreBrandProfileRequest;
use App\Jobs\AutofillBrandFromWebsiteJob;
use App\Jobs\GenerateInfluencerBriefJob;
use App\Models\BrandProfile;
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
    public function show(Request $request): Response|RedirectResponse
    {
        if ($request->user()->brandProfile()->exists()) {
            return redirect()->route('feed.index');
        }

        return Inertia::render('onboarding/Index', [
            'brand' => null,
            'platforms' => collect(Platform::cases())->map(fn (Platform $platform) => $platform->value)->values(),
        ]);
    }

    public function store(StoreBrandProfileRequest $request): RedirectResponse
    {
        BrandProfile::query()->updateOrCreate(
            ['user_id' => $request->user()->id],
            [
                'name' => $request->validated('name'),
                'website' => $request->validated('website'),
                'description' => $request->validated('description'),
                'own_handles' => $request->validated('own_handles') ?? [],
            ],
        );

        GenerateInfluencerBriefJob::dispatch($request->user()->id);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Brand profile saved. Add competitors to start tracking.'),
        ]);

        return redirect()->route('competitors.index');
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
}
