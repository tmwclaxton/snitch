<?php

namespace App\Http\Requests\Onboarding;

use App\Enums\Platform;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreOnboardingCompetitorsRequest extends FormRequest
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
            'own_handle' => ['nullable', 'string', 'max:80'],
            'competitors' => ['required', 'array', 'min:1', 'max:3'],
            'competitors.*.platform' => ['required', 'string', Rule::enum(Platform::class)],
            'competitors.*.handle' => ['required', 'string', 'max:80'],
            'competitors.*.display_name' => ['nullable', 'string', 'max:120'],
            'competitors.*.avatar' => ['nullable', 'string', 'max:2048'],
            'competitors.*.followers' => ['nullable', 'integer', 'min:0'],
            'competitors.*.url' => ['nullable', 'string', 'max:2048'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $competitors = $this->input('competitors');

        if (! is_array($competitors)) {
            return;
        }

        $normalized = [];

        foreach ($competitors as $row) {
            if (! is_array($row)) {
                continue;
            }

            $handle = $row['handle'] ?? null;
            $normalized[] = [
                ...$row,
                'platform' => $row['platform'] ?? Platform::Instagram->value,
                'handle' => is_string($handle) ? ltrim(trim($handle), '@') : $handle,
            ];
        }

        $own = $this->input('own_handle');

        $this->merge([
            'competitors' => $normalized,
            'own_handle' => is_string($own) ? ltrim(trim($own), '@') : $own,
        ]);
    }
}
