<?php

namespace App\Http\Requests\Company;

use Illuminate\Foundation\Http\FormRequest;

class ImportCompaniesRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return (bool) ($user?->isCompanyAdmin() || $user?->isGlobalAdmin());
    }

    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:10240'],
            'tenant_id' => ['nullable', 'integer', 'exists:tenants,id'],
        ];
    }
}
