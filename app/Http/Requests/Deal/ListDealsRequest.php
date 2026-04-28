<?php

namespace App\Http\Requests\Deal;

use Illuminate\Foundation\Http\FormRequest;

class ListDealsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user();
    }

    public function rules(): array
    {
        return [
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'pipeline_id' => ['nullable', 'integer', 'exists:pipelines,id'],
            'stage_id' => ['nullable', 'integer', 'exists:pipeline_stages,id'],
            'contact_id' => ['nullable', 'integer', 'exists:contacts,id'],
            'owner_id' => ['nullable', 'integer', 'exists:users,id'],
            'status' => ['nullable', 'integer', 'in:0,1,2'],
            'search' => ['nullable', 'string', 'max:120'],
            'tenant_id' => ['nullable', 'integer', 'exists:tenants,id'],
        ];
    }
}
