<?php

namespace App\Http\Requests\Prm;

use Illuminate\Foundation\Http\FormRequest;

class PartnerLeadStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user();
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'contact_email' => ['nullable', 'email', 'max:255'],
            'contact_first_name' => ['nullable', 'string', 'max:120'],
            'contact_last_name' => ['nullable', 'string', 'max:120'],
            'company_name' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:64'],
            'description' => ['nullable', 'string'],
            'status' => ['nullable', 'string', 'max:32'],
            'approval_status' => ['nullable', 'string', 'max:32'],
            'assigned_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'metadata' => ['nullable', 'array'],
        ];
    }
}
