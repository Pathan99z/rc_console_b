<?php

namespace App\Http\Requests\Prm;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PatchPrmResourceStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user();
    }

    public function rules(): array
    {
        return [
            'status' => ['required', 'string', 'in:active,inactive'],
            'tenant_id' => [
                Rule::requiredIf(fn () => $this->user()->isGlobalAdmin()),
                'nullable',
                'integer',
                'exists:tenants,id',
            ],
        ];
    }
}
