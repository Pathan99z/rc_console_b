<?php

namespace App\Http\Requests\Organization;

use Illuminate\Foundation\Http\FormRequest;

class CreateOrganizationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user();
    }

    public function rules(): array
    {
        return [
            'tenant_id' => ['nullable', 'integer', 'exists:tenants,id'],
            'parent_organization_id' => ['nullable', 'integer', 'exists:organizations,id'],
            'type' => ['required', 'in:company,partner,reseller'],
            'legal_name' => ['required', 'string', 'max:255'],
            'display_name' => ['required', 'string', 'max:255'],
            'registration_number' => ['nullable', 'string', 'max:100'],
            'tax_number' => ['nullable', 'string', 'max:100'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'website' => ['nullable', 'url', 'max:255'],
            'address_line_1' => ['nullable', 'string', 'max:255'],
            'address_line_2' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:120'],
            'state' => ['nullable', 'string', 'max:120'],
            'country' => ['nullable', 'string', 'max:120'],
            'postal_code' => ['nullable', 'string', 'max:40'],
            'onboarding_status' => ['nullable', 'in:draft,pending_review,approved,active,suspended,rejected'],
            'status' => ['nullable', 'in:active,inactive'],
            'credit_limit' => ['nullable', 'numeric', 'min:0'],
            'metadata' => ['nullable', 'array'],
        ];
    }
}
