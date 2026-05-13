<?php

namespace App\Http\Requests\Deal;

use Illuminate\Foundation\Http\FormRequest;

class UpdateDealRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user();
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'contact_id' => ['nullable', 'integer', 'exists:contacts,id'],
            'company_id' => ['nullable', 'integer', 'exists:companies,id'],
            'owner_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'pipeline_id' => ['nullable', 'integer', 'exists:pipelines,id'],
            'pipeline_stage_id' => ['nullable', 'integer', 'exists:pipeline_stages,id'],
            'estimated_value' => ['nullable', 'numeric', 'min:0'],
            'currency_code' => ['nullable', 'string', 'regex:/^[A-Za-z]{3}$/'],
            'probability' => ['nullable', 'integer', 'min:0', 'max:100'],
            'expected_close_date' => ['nullable', 'date'],
            'status' => ['nullable', 'in:open,won,lost'],
            'meta' => ['nullable', 'array'],
            'partner_organization_id' => ['nullable', 'integer', 'exists:organizations,id'],
            'partner_opportunity_fingerprint' => ['nullable', 'string', 'max:64'],
        ];
    }
}
