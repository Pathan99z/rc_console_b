<?php

namespace App\Http\Requests\Organization;

use App\Models\Role;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class InviteOrganizationUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user();
    }

    public function rules(): array
    {
        return [
            'email' => ['required', 'email', 'max:255'],
            'role_code' => ['required', 'string', Rule::in([
                Role::CODE_PARTNER_ADMIN,
                Role::CODE_RESELLER_ADMIN,
                Role::CODE_RESELLER_SALES_MANAGER,
                Role::CODE_RESELLER_SALES_CONSULTANT,
            ])],
            'expires_in_days' => ['nullable', 'integer', 'min:1', 'max:90'],
        ];
    }
}
