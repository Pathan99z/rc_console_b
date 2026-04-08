<?php

namespace App\Http\Requests\Tenant;

use Illuminate\Foundation\Http\FormRequest;

class ListTenantsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->isGlobalAdmin();
    }

    public function rules(): array
    {
        return [
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
