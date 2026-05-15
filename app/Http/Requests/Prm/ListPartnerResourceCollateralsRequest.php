<?php

namespace App\Http\Requests\Prm;

use Illuminate\Foundation\Http\FormRequest;

class ListPartnerResourceCollateralsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user();
    }

    public function rules(): array
    {
        return [
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'resource_category' => ['nullable', 'string', 'max:64'],
            'product_id' => ['nullable', 'integer', 'exists:products,id'],
            'search' => ['nullable', 'string', 'max:200'],
        ];
    }
}
