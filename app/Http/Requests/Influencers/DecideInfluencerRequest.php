<?php

namespace App\Http\Requests\Influencers;

use App\Enums\Platform;
use App\Models\TrackedAccount;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DecideInfluencerRequest extends FormRequest
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
            'handle' => ['required', 'string', 'max:80'],
            'run_id' => ['nullable', 'uuid'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('handle')) {
            $this->merge([
                'handle' => ltrim((string) $this->input('handle'), '@'),
            ]);
        }
    }
}
