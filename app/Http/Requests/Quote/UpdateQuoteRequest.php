<?php

namespace App\Http\Requests\Quote;

use Illuminate\Foundation\Http\FormRequest;

class UpdateQuoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user();
    }

    public function rules(): array
    {
        return [
            'contact_id' => ['required', 'integer', 'exists:contacts,id'],
            'deal_id' => ['nullable', 'integer', 'exists:deals,id'],
            'quote_type' => ['nullable', 'integer', 'in:0,1'],
            'notes' => ['nullable', 'string', 'max:10000'],
            'valid_until' => ['nullable', 'date'],
            'discount_total' => ['nullable', 'numeric', 'min:0'],
            'currency_code' => ['nullable', 'string', 'size:3'],
            'products' => ['required', 'array', 'min:1'],
            'products.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'products.*.quantity' => ['required', 'integer', 'min:1'],
            'products.*.unit_price' => ['nullable', 'numeric', 'min:0'],
            'products.*.tax_rate' => ['nullable', 'numeric', 'min:0'],
        ];
    }
}
