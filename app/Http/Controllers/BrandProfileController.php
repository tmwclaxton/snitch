<?php

namespace App\Http\Controllers;

use App\Enums\Platform;
use App\Http\Requests\Settings\UpdateBrandProfileRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class BrandProfileController extends Controller
{
    public function edit(Request $request): Response
    {
        $brand = $request->user()->brandProfile;

        abort_unless($brand !== null, 404);

        $this->authorize('update', $brand);

        return Inertia::render('brand/Index', [
            'brand' => [
                'name' => $brand->name,
                'website' => $brand->website,
                'description' => $brand->description,
                'own_handles' => $brand->own_handles ?? [],
            ],
            'platforms' => collect(Platform::cases())->map(fn (Platform $platform) => $platform->value)->values(),
        ]);
    }

    public function update(UpdateBrandProfileRequest $request): RedirectResponse
    {
        $brand = $request->user()->brandProfile;

        abort_unless($brand !== null, 404);

        $brand->update([
            'name' => $request->validated('name'),
            'website' => $request->validated('website'),
            'description' => $request->validated('description'),
            'own_handles' => $request->validated('own_handles') ?? [],
        ]);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Brand profile updated.'),
        ]);

        return to_route('brand.edit');
    }
}
