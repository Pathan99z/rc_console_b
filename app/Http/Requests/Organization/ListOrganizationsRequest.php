<?php

namespace App\Http\Requests\Organization;

use Illuminate\Foundation\Http\FormRequest;

class ListOrganizationsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user();
    }

    public function rules(): array
    {
        return [
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'type' => ['nullable', 'in:company,partner,reseller'],
            'status' => ['nullable', 'in:active,inactive'],
            'onboarding_status' => ['nullable', 'in:draft,pending_review,approved,active,suspended,rejected'],
            'search' => ['nullable', 'string', 'max:100'],
            'tenant_id' => ['nullable', 'integer', 'exists:tenants,id'],
        ];
    }
}
