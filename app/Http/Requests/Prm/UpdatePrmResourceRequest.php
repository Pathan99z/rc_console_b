<?php

namespace App\Http\Requests\Prm;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePrmResourceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user();
    }

    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'resource_category' => ['sometimes', 'string', 'max:64'],
            'product_id' => ['nullable', 'integer', 'exists:products,id'],
            'file' => ['sometimes', 'file', 'mimes:pdf,doc,docx,ppt,pptx,jpg,jpeg,png,webp', 'max:20480'],
            'partner_visible' => ['sometimes', 'boolean'],
            'reseller_visible' => ['sometimes', 'boolean'],
            'status' => ['sometimes', 'string', 'in:active,inactive'],
            'metadata' => ['nullable', 'array'],
            'tenant_id' => [
                Rule::requiredIf(fn () => $this->user()->isGlobalAdmin()),
                'nullable',
                'integer',
                'exists:tenants,id',
            ],
        ];
    }
}
