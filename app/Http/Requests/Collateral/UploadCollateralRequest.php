<?php

namespace App\Http\Requests\Collateral;

use Illuminate\Foundation\Http\FormRequest;

class UploadCollateralRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user();
    }

    public function rules(): array
    {
        return [
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', 'string', 'max:100'],
            'file' => ['required', 'file', 'mimes:pdf,doc,docx,ppt,pptx,jpg,jpeg,png,webp', 'max:20480'],
            'tenant_id' => ['nullable', 'integer', 'exists:tenants,id'],
        ];
    }
}
