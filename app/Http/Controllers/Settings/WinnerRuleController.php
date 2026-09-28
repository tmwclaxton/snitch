<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\UpdateWinnerRuleRequest;
use App\Jobs\ScoreWinnersJob;
use App\Services\Winners\WinnerScorer;
use Illuminate\Http\RedirectResponse;

class WinnerRuleController extends Controller
{
    public function update(UpdateWinnerRuleRequest $request, WinnerScorer $scorer): RedirectResponse
    {
        $data = $request->validated();
        $rule = $scorer->ruleFor($request->user());

        if (($data['preset'] ?? 'custom') !== 'custom') {
            $preset = config('snitch.winners.presets.'.$data['preset'], []);
            $data = array_merge($data, $preset);
        }

        $rule->fill([
            'preset' => $data['preset'],
            'min_multiplier' => $data['min_multiplier'] ?? $rule->min_multiplier ?? 2.0,
            'min_engagement_rate' => $data['min_engagement_rate'] ?? $rule->min_engagement_rate,
            'min_views' => $data['min_views'] ?? $rule->min_views ?? 0,
            'min_likes' => $data['min_likes'] ?? $rule->min_likes ?? 0,
            'recency_days' => $data['recency_days'] ?? $rule->recency_days,
            'weights' => $data['weights'] ?? $rule->weights,
            'advanced' => $data['advanced'] ?? $rule->advanced,
        ])->save();

        ScoreWinnersJob::queueFor($request->user()->id);

        return back();
    }
}
