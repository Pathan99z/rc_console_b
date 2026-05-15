<?php

namespace App\Http\Requests\Prm;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PrmResourceAnalyticsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user();
    }

    public function rules(): array
    {
        return [
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'collateral_id' => ['nullable', 'integer', 'exists:collaterals,id'],
            'partner_organization_id' => ['nullable', 'integer', 'exists:organizations,id'],
            'tenant_id' => [
                Rule::requiredIf(fn () => $this->user()->isGlobalAdmin()),
                'nullable',
                'integer',
                'exists:tenants,id',
            ],
        ];
    }
}
