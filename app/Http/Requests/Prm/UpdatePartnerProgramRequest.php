<?php

namespace App\Http\Requests\Prm;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePartnerProgramRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user();
    }

    public function rules(): array
    {
        return [
            'code' => ['sometimes', 'required', 'string', 'max:64'],
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'tier_level' => ['sometimes', 'required', 'integer', 'min:0', 'max:255'],
            'default_commission_percent' => ['sometimes', 'required', 'numeric', 'min:0', 'max:100'],
            'rules' => ['nullable', 'array'],
            'metadata' => ['nullable', 'array'],
            'is_template' => ['sometimes', 'boolean'],
        ];
    }
}
