<?php

namespace App\Http\Requests\Payment;

use App\Models\TenantPaymentSetting;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpsertPaymentSettingsRequest extends FormRequest
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
            'payfast_mode' => ['required', 'string', Rule::in([TenantPaymentSetting::MODE_SANDBOX, TenantPaymentSetting::MODE_LIVE])],
            'merchant_id' => ['required', 'string', 'max:64'],
            'merchant_key' => ['required', 'string', 'max:512'],
            'passphrase' => ['nullable', 'string', 'max:512'],
            'return_url' => ['nullable', 'string', 'url', 'max:2048'],
            'cancel_url' => ['nullable', 'string', 'url', 'max:2048'],
            'notify_url' => ['nullable', 'string', 'url', 'max:2048'],
        ];
    }
}
