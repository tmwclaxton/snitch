<?php

namespace App\Http\Requests\Influencers;

use App\Models\TrackedAccount;
use Illuminate\Foundation\Http\FormRequest;

class UpdateInfluencerBriefRequest extends FormRequest
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
        return [
            'influencer_brief' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
