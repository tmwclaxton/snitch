<?php

namespace App\Http\Controllers;

use App\Http\Requests\Features\StoreFeatureSuggestionRequest;
use App\Models\FeatureSuggestion;
use App\Services\Features\FeatureSuggestionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

class FeatureSuggestionController extends Controller
{
    public function __construct(private FeatureSuggestionService $suggestions) {}

    public function store(StoreFeatureSuggestionRequest $request): RedirectResponse
    {
        $this->suggestions->submit($request->user(), $request->validated());

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Idea submitted. Thanks for the vote.'),
        ]);

        return redirect()->back();
    }

    public function vote(Request $request, FeatureSuggestion $suggestion): RedirectResponse
    {
        $this->suggestions->toggleVote($request->user(), $suggestion);

        return redirect()->back();
    }
}
