<?php

namespace App\Http\Requests\Collateral;

use Illuminate\Foundation\Http\FormRequest;

class SendCollateralRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user();
    }

    public function rules(): array
    {
        return [
            'contact_id' => ['nullable', 'integer', 'exists:contacts,id', 'required_without:email'],
            'email' => ['nullable', 'email', 'max:255', 'required_without:contact_id'],
            'message' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
