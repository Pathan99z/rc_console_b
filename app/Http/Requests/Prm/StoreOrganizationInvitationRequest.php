<?php

namespace App\Http\Requests\Prm;

use App\Models\Role;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreOrganizationInvitationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user();
    }

    public function rules(): array
    {
        return [
            'email' => ['required', 'email', 'max:255'],
            'role_code' => ['required', 'string', Rule::in([Role::CODE_PARTNER_ADMIN, Role::CODE_RESELLER_ADMIN])],
            'expires_in_days' => ['nullable', 'integer', 'min:1', 'max:90'],
        ];
    }
}
