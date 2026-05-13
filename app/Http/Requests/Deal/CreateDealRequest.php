<?php

namespace App\Http\Requests\Deal;

use Illuminate\Foundation\Http\FormRequest;

class CreateDealRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user();
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'contact_id' => ['required', 'integer', 'exists:contacts,id'],
            'company_id' => ['nullable', 'integer', 'exists:companies,id'],
            'owner_user_id' => ['required', 'integer', 'exists:users,id'],
            'pipeline_id' => ['required', 'integer', 'exists:pipelines,id'],
            'pipeline_stage_id' => ['required', 'integer', 'exists:pipeline_stages,id'],
            'estimated_value' => ['nullable', 'numeric', 'min:0'],
            'currency_code' => ['nullable', 'string', 'regex:/^[A-Za-z]{3}$/'],
            'probability' => ['nullable', 'integer', 'min:0', 'max:100'],
            'expected_close_date' => ['nullable', 'date'],
            'status' => ['nullable', 'in:open,won,lost'],
            'meta' => ['nullable', 'array'],
            'tenant_id' => ['nullable', 'integer', 'exists:tenants,id'],
            'partner_organization_id' => ['nullable', 'integer', 'exists:organizations,id'],
            'partner_opportunity_fingerprint' => ['nullable', 'string', 'max:64'],
        ];
    }
}
