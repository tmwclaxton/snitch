<?php

namespace App\Http\Requests\Influencers;

use App\Enums\Platform;
use App\Models\TrackedAccount;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SearchInfluencersRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', TrackedAccount::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $platforms = array_map(fn (Platform $platform): string => $platform->value, Platform::cases());

        return [
            'platform' => ['required', 'string', Rule::in($platforms)],
            'language' => ['nullable', 'string', 'max:40'],
            'min_followers' => ['nullable', 'integer', 'min:0', 'max:100000000'],
            'max_followers' => ['nullable', 'integer', 'min:0', 'max:100000000', 'gte:min_followers'],
            'brief' => ['required', 'string', 'min:8', 'max:2000'],
        ];
    }
}
