<?php

namespace App\Http\Requests\Company;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCompanyRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return (bool) ($user?->isCompanyAdmin() || $user?->isGlobalAdmin());
    }

    public function rules(): array
    {
        $tenantId = (int) ($this->user()?->tenant_id ?? $this->input('tenant_id'));
        $companyId = (int) $this->route('companyId');

        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'industry' => ['nullable', 'string', 'max:100'],
            'company_type' => ['nullable', 'string', 'max:100'],
            'employees' => ['nullable', 'integer', 'min:0'],
            'revenue' => ['nullable', 'numeric', 'min:0'],
            'timezone' => ['nullable', 'string', 'max:100'],
            'linkedin_url' => ['nullable', 'string', 'url', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:120'],
            'state' => ['nullable', 'string', 'max:120'],
            'postal_code' => ['nullable', 'string', 'max:30'],
            'country' => ['nullable', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:5000'],
            'email' => [
                'nullable',
                'string',
                'email',
                'max:255',
                Rule::unique('companies', 'email')
                    ->ignore($companyId)
                    ->where(fn ($q) => $q->where('tenant_id', $tenantId)),
            ],
            'phone' => ['nullable', 'string', 'max:30'],
            'website' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'integer', 'in:0,1'],
            'assigned_user_id' => ['nullable', 'integer', 'exists:users,id'],
        ];
    }
}
