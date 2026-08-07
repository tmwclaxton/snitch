<?php

namespace App\Http\Requests\Settings;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateWinnerRuleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'preset' => ['required', Rule::in(['conservative', 'balanced', 'aggressive', 'custom'])],
            'min_engagement_rate' => ['nullable', 'integer', 'min:0', 'max:100'],
            'min_views' => ['nullable', 'integer', 'min:0'],
            'min_likes' => ['nullable', 'integer', 'min:0'],
            'recency_days' => ['nullable', 'integer', 'min:1', 'max:365'],
            'weights' => ['nullable', 'array'],
            'weights.views' => ['nullable', 'numeric', 'min:0', 'max:1'],
            'weights.likes' => ['nullable', 'numeric', 'min:0', 'max:1'],
            'weights.comments' => ['nullable', 'numeric', 'min:0', 'max:1'],
            'weights.shares' => ['nullable', 'numeric', 'min:0', 'max:1'],
            'advanced' => ['nullable', 'array'],
            'advanced.require_hook' => ['nullable', 'boolean'],
            'advanced.require_sfx' => ['nullable', 'boolean'],
            'advanced.min_score' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ];
    }
}
