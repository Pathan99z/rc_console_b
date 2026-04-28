<?php

namespace App\Http\Requests\Collateral;

use Illuminate\Foundation\Http\FormRequest;

class ListCollateralsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user();
    }

    public function rules(): array
    {
        return [
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'product_id' => ['nullable', 'integer', 'exists:products,id'],
            'type' => ['nullable', 'string', 'max:100'],
            'file_type' => ['nullable', 'string', 'max:120'],
            'search' => ['nullable', 'string', 'max:150'],
            'tenant_id' => ['nullable', 'integer', 'exists:tenants,id'],
        ];
    }
}
