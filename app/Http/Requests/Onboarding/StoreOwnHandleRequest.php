<?php

namespace App\Http\Requests\Onboarding;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreOwnHandleRequest extends FormRequest
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
            'handle' => ['required', 'string', 'min:3', 'max:80'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $handle = $this->input('handle');

        if (is_string($handle)) {
            $this->merge(['handle' => ltrim(trim($handle), '@')]);
        }
    }
}
