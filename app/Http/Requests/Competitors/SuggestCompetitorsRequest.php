<?php

namespace App\Http\Requests\Competitors;

use App\Enums\Platform;
use App\Models\TrackedAccount;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SuggestCompetitorsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', TrackedAccount::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $platforms = array_map(fn (Platform $platform): string => $platform->value, Platform::cases());

        return [
            'platforms' => ['required', 'array', 'min:1'],
            'platforms.*' => ['required', 'string', Rule::in($platforms)],
            'brief' => ['required', 'string', 'min:8', 'max:2000'],
        ];
    }
}
