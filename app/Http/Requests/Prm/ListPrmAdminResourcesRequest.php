<?php

namespace App\Http\Requests\Prm;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListPrmAdminResourcesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user();
    }

    public function rules(): array
    {
        return [
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'tenant_id' => [
                Rule::requiredIf(fn () => $this->user()->isGlobalAdmin()),
                'nullable',
                'integer',
                'exists:tenants,id',
            ],
            'search' => ['nullable', 'string', 'max:200'],
            'status' => ['nullable', 'string', 'in:active,inactive,all'],
            'resource_category' => ['nullable', 'string', 'max:64'],
            'product_id' => ['nullable', 'integer', 'exists:products,id'],
        ];
    }
}
