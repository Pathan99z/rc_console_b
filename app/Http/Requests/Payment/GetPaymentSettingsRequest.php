<?php

namespace App\Http\Requests\Payment;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class GetPaymentSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'tenant_id' => [
                Rule::requiredIf(fn () => $this->user()?->isGlobalAdmin()),
                'nullable',
                'integer',
                'exists:tenants,id',
            ],
        ];
    }
}
