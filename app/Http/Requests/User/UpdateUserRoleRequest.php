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
            'role' => ['required', 'in:user,company_admin,partner_admin,partner_sales_manager,partner_sales_consultant,reseller_admin,reseller_sales_consultant'],
        ];
    }
}
