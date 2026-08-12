<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AdminUserIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:120'],
            'sort' => ['nullable', 'string', Rule::in(['created_at', 'email', 'name', 'balance'])],
            'direction' => ['nullable', 'string', Rule::in(['asc', 'desc'])],
            'plan' => ['nullable', 'string', Rule::in(['subscribed', 'none'])],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
