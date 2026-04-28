<?php

namespace App\Http\Requests\Contact;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateContactRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user();
    }

    public function rules(): array
    {
        $tenantId = (int) ($this->user()?->tenant_id ?? $this->input('tenant_id'));
        $contactId = (int) $this->route('contactId');

        return [
            'first_name' => ['sometimes', 'string', 'max:100'],
            'last_name' => ['nullable', 'string', 'max:100'],
            'email' => [
                'nullable',
                'string',
                'email',
                'max:255',
                Rule::unique('contacts', 'email')
                    ->ignore($contactId)
                    ->where(fn ($q) => $q->where('tenant_id', $tenantId)->whereNull('deleted_at')),
            ],
            'phone' => ['nullable', 'string', 'max:30'],
            'lifecycle_stage' => ['nullable', 'integer', 'in:0,1,2,3'],
            'company_id' => ['nullable', 'integer', 'exists:companies,id'],
            'assigned_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'meta' => ['nullable', 'array'],
        ];
    }
}
