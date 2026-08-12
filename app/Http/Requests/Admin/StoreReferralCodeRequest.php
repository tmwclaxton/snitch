<?php

namespace App\Http\Requests\Admin;

use App\Services\Referrals\ReferralAttribution;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreReferralCodeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->isAdmin();
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'code' => [
                'required',
                'string',
                'max:64',
                'regex:/^[a-z0-9][a-z0-9_-]{1,62}$/',
                Rule::unique('referral_codes', 'code'),
            ],
            'name' => ['required', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function validated($key = null, $default = null): mixed
    {
        /** @var array<string, mixed> $validated */
        $validated = parent::validated();

        if (isset($validated['code']) && is_string($validated['code'])) {
            $validated['code'] = app(ReferralAttribution::class)->normalizeCode($validated['code']) ?? $validated['code'];
        }

        if ($key !== null) {
            return data_get($validated, $key, $default);
        }

        return $validated;
    }
}
