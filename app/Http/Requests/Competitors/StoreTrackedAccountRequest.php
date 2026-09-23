<?php

namespace App\Http\Requests\Competitors;

use App\Enums\Platform;
use App\Models\TrackedAccount;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTrackedAccountRequest extends FormRequest
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
        return [
            'platform' => ['sometimes', Rule::enum(Platform::class)],
            'handle' => ['required', 'string', 'max:80'],
            'display_name' => ['nullable', 'string', 'max:120'],
            'is_own_account' => ['sometimes', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $handle = $this->input('handle');
        $merged = [
            'platform' => $this->input('platform') ?: Platform::Instagram->value,
        ];

        if (is_string($handle)) {
            $merged['handle'] = ltrim(trim($handle), '@');
        }

        $this->merge($merged);
    }
}
