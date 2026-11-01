<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\OmitsProductDataWhenPaywalled;
use App\Services\Brief\ExecutiveBriefPresenter;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ExecutiveBriefController extends Controller
{
    use OmitsProductDataWhenPaywalled;

    public function __invoke(
        Request $request,
        ExecutiveBriefPresenter $briefs,
    ): Response {
        $user = $request->user();

        if ($this->productAccessBlocked($user)) {
            return Inertia::render('ExecutiveBrief', [
                'brief' => [
                    'empty' => true,
                    'empty_reason' => 'Subscribe to unlock your Executive Brief.',
                    'brief_date' => null,
                    'generated_at' => null,
                    'headline' => null,
                    'summary' => null,
                    'own_activity' => [],
                    'competitor_activity' => [],
                    'suggestions' => [],
                    'freshness' => [],
                    'chat_url' => route('chat.index'),
                    'competitors_url' => route('competitors.index'),
                    'curator_overrides' => [
                        'summary' => false,
                        'suggestions' => false,
                        'updated_at' => null,
                    ],
                    'insufficient_data' => true,
                    'app_url' => route('executive-brief.index'),
                ],
            ]);
        }

        return Inertia::render('ExecutiveBrief', [
            'brief' => $briefs->forUser($user),
        ]);
    }
}
