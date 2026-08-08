<?php

namespace App\Http\Requests\Competitors;

use App\Enums\Platform;
use App\Models\TrackedAccount;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ConfirmSuggestionsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', TrackedAccount::class) ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'suggestions' => ['required', 'array', 'min:1'],
            'suggestions.*.platform' => ['required', Rule::enum(Platform::class)],
            'suggestions.*.handle' => ['required', 'string', 'max:80'],
            'suggestions.*.display_name' => ['nullable', 'string', 'max:120'],
            'suggestions.*.avatar' => ['nullable', 'string', 'max:500'],
            'suggestions.*.source' => ['nullable', 'string', 'max:200'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $suggestions = $this->input('suggestions');

        if (! is_array($suggestions)) {
            return;
        }

        $this->merge([
            'suggestions' => collect($suggestions)
                ->map(function (mixed $suggestion): mixed {
                    if (! is_array($suggestion) || ! is_string($suggestion['handle'] ?? null)) {
                        return $suggestion;
                    }

                    return [
                        ...$suggestion,
                        'handle' => ltrim(trim($suggestion['handle']), '@'),
                    ];
                })
                ->all(),
        ]);
    }
}
