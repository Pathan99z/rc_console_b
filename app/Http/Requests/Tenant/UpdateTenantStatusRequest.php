<?php

namespace App\Http\Requests\Tenant;

use Illuminate\Foundation\Http\FormRequest;

class UpdateTenantStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->isGlobalAdmin();
    }

    public function rules(): array
    {
        return [
            'status' => ['required', 'in:active,suspended'],
        ];
    }
}
