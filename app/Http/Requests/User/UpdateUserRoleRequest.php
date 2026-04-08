<?php

namespace App\Http\Requests\User;

use Illuminate\Foundation\Http\FormRequest;

class UpdateUserRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->isGlobalAdmin();
    }

    public function rules(): array
    {
        return [
            'role' => ['required', 'in:user,company_admin'],
        ];
    }
}
