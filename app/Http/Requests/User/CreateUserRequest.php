<?php

namespace App\Http\Requests\User;

use Illuminate\Foundation\Http\FormRequest;

class CreateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return (bool) ($user?->isCompanyAdmin() || $user?->isGlobalAdmin());
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8'],
            'status' => ['nullable', 'in:active,inactive,suspended'],
            'role' => ['nullable', 'in:user,company_admin,partner_admin,partner_sales_manager,partner_sales_consultant,reseller_admin,reseller_sales_consultant'],
            'tenant_id' => ['nullable', 'integer', 'exists:tenants,id'],
            'organization_id' => ['nullable', 'integer', 'exists:organizations,id'],
            'team_id' => ['nullable', 'integer', 'exists:teams,id'],
            'data_scope' => ['nullable', 'in:self,team'],
            'manager_id' => ['nullable', 'integer', 'exists:users,id'],
        ];
    }
}
