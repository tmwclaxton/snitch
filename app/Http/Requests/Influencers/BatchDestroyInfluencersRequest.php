<?php

namespace App\Http\Requests\Influencers;

use App\Models\TrackedAccount;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class BatchDestroyInfluencersRequest extends FormRequest
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
            'ids' => ['required', 'array', 'min:1', 'max:50'],
            'ids.*' => ['required', 'integer', 'distinct'],
        ];
    }
}
