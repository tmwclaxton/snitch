<?php

namespace App\Http\Requests\Influencers;

use App\Enums\Platform;
use App\Models\TrackedAccount;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BatchDecideInfluencersRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', TrackedAccount::class) ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $platforms = array_map(fn (Platform $platform): string => $platform->value, Platform::cases());

        return [
            'run_id' => ['nullable', 'uuid'],
            'suggestions' => ['required', 'array', 'min:1', 'max:50'],
            'suggestions.*.platform' => ['required', 'string', Rule::in($platforms)],
            'suggestions.*.handle' => ['required', 'string', 'max:80'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $suggestions = $this->input('suggestions');

        if (! is_array($suggestions)) {
            return;
        }

        $normalized = [];

        foreach ($suggestions as $suggestion) {
            if (! is_array($suggestion)) {
                continue;
            }

            $normalized[] = [
                ...$suggestion,
                'handle' => ltrim((string) ($suggestion['handle'] ?? ''), '@'),
            ];
        }

        $this->merge(['suggestions' => $normalized]);
    }
}
