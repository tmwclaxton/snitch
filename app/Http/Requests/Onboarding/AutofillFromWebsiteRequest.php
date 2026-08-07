<?php

namespace App\Http\Requests\Onboarding;

use App\Support\WebsiteUrl;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class AutofillFromWebsiteRequest extends FormRequest
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
            'website' => ['required', 'url', 'max:255'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $website = $this->input('website');

            if (! is_string($website) || WebsiteUrl::hasValidHost($website)) {
                return;
            }

            $validator->errors()->add('website', 'Enter a valid website domain.');
        });
    }

    protected function prepareForValidation(): void
    {
        $raw = $this->input('website');

        if (! is_string($raw)) {
            return;
        }

        $website = WebsiteUrl::normalize($raw);

        if ($website !== null) {
            $this->merge(['website' => $website]);
        }
    }
}
