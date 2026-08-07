<?php

namespace App\Http\Requests\Settings;

use App\Support\WebsiteUrl;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateBrandProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        $brand = $this->user()?->brandProfile;

        return $brand !== null && $this->user()?->can('update', $brand) === true;
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
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $website = $this->input('website');

            if ($website === null || $website === '' || ! is_string($website)) {
                return;
            }

            if (WebsiteUrl::hasValidHost($website)) {
                return;
            }

            $validator->errors()->add('website', 'Enter a valid website domain.');
        });
    }

    protected function prepareForValidation(): void
    {
        if (! $this->filled('website')) {
            $this->merge(['website' => null]);

            return;
        }

        $raw = $this->input('website');

        if (! is_string($raw)) {
            return;
        }

        $this->merge([
            'website' => WebsiteUrl::normalize($raw),
        ]);
    }
}
