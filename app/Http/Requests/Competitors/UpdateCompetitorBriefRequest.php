<?php

namespace App\Http\Requests\Competitors;

use App\Models\TrackedAccount;
use Illuminate\Foundation\Http\FormRequest;

class UpdateCompetitorBriefRequest extends FormRequest
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
        return [
            'competitor_brief' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
