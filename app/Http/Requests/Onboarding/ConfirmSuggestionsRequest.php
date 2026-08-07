<?php

namespace App\Http\Requests\Onboarding;

use App\Enums\Platform;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ConfirmSuggestionsRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:120'],
            'website' => ['nullable', 'url', 'max:255'],
            'description' => ['required', 'string', 'max:1000'],
            'own_handles' => ['nullable', 'array'],
            'own_handles.instagram' => ['nullable', 'string', 'max:80'],
            'own_handles.tiktok' => ['nullable', 'string', 'max:80'],
            'own_handles.facebook' => ['nullable', 'string', 'max:80'],
            'own_handles.linkedin' => ['nullable', 'string', 'max:80'],
            'own_handles.pinterest' => ['nullable', 'string', 'max:80'],
            'suggestions' => ['required', 'array', 'min:1'],
            'suggestions.*.platform' => ['required', Rule::enum(Platform::class)],
            'suggestions.*.handle' => ['required', 'string', 'max:80'],
            'suggestions.*.url' => ['nullable', 'url', 'max:255'],
            'suggestions.*.display_name' => ['nullable', 'string', 'max:120'],
            'suggestions.*.avatar' => ['nullable', 'string', 'max:500'],
        ];
    }
}
