<?php

namespace App\Http\Requests\Competitors;

use App\Models\TrackedAccount;
use App\Support\SyncOptions;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class SyncTrackedAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        $trackedAccount = $this->route('trackedAccount');

        return $trackedAccount instanceof TrackedAccount
            && ($this->user()?->can('update', $trackedAccount) ?? false);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return SyncOptions::optionalFieldRules();
    }
}
