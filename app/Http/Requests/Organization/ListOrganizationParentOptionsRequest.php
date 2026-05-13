<?php

namespace App\Http\Requests\Organization;

use Illuminate\Foundation\Http\FormRequest;

class ListOrganizationParentOptionsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user();
    }

    public function rules(): array
    {
        return [
            'child_type' => ['required', 'in:company,partner,reseller'],
            'tenant_id' => ['nullable', 'integer', 'exists:tenants,id'],
            'include_inactive' => ['nullable', 'boolean'],
        ];
    }
}
