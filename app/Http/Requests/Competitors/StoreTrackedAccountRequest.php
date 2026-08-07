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
        return $this->user()?->can('create', TrackedAccount::class) ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'platform' => ['required', Rule::enum(Platform::class)],
            'handle' => ['required', 'string', 'max:80'],
            'url' => ['nullable', 'url', 'max:255'],
            'display_name' => ['nullable', 'string', 'max:120'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $handle = $this->input('handle');

        if (is_string($handle)) {
            $this->merge([
                'handle' => ltrim(trim($handle), '@'),
            ]);
        }
    }
}
