<?php

namespace App\Http\Requests\Settings;

use App\Enums\BillingVendor;
use App\Services\Billing\UsageBillingService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListBillingChargesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        $days = $this->query('days');

        if ($days !== null && $days !== '') {
            $this->merge(['days' => (int) $days]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'vendor' => ['sometimes', 'nullable', 'string', Rule::enum(BillingVendor::class)],
            'action' => [
                'sometimes',
                'nullable',
                'string',
                'max:64',
                Rule::in(app(UsageBillingService::class)->ledgerActionOptions()),
            ],
            'days' => ['sometimes', 'nullable', 'integer', Rule::in([7, 30, 90])],
        ];
    }

    /**
     * @return array{vendor: string|null, action: string|null, days: int|null}
     */
    public function filters(): array
    {
        $vendor = $this->validated('vendor');
        $action = $this->validated('action');
        $days = $this->validated('days');

        return [
            'vendor' => is_string($vendor) && $vendor !== '' ? $vendor : null,
            'action' => is_string($action) && $action !== '' ? $action : null,
            'days' => is_int($days) ? $days : null,
        ];
    }
}
