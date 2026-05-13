<?php

namespace App\Http\Requests\Prm;

use Illuminate\Foundation\Http\FormRequest;

class AcceptOrganizationInvitationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'token' => ['required', 'string', 'min:10'],
            'name' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'terms_accepted' => ['required', 'boolean', 'accepted'],
        ];
    }
}
