<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateFeatureSuggestionRequest;
use App\Models\FeatureSuggestion;
use App\Services\Features\FeatureSuggestionService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class FeatureSuggestionController extends Controller
{
    public function __construct(private FeatureSuggestionService $suggestions) {}

    public function index(): Response
    {
        return Inertia::render('admin/Features', [
            'suggestions' => $this->suggestions->forAdmin()->values()->all(),
            'statuses' => FeatureSuggestion::STATUSES,
        ]);
    }

    public function update(
        UpdateFeatureSuggestionRequest $request,
        FeatureSuggestion $suggestion,
    ): RedirectResponse {
        $this->suggestions->updateStatus($suggestion, $request->validated('status'));

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Feature status updated.'),
        ]);

        return redirect()->back();
    }
}
