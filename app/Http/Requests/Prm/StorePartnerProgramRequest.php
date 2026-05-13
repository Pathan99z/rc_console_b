<?php

namespace App\Http\Requests\Prm;

use App\Models\PartnerProgram;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePartnerProgramRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user();
    }

    public function rules(): array
    {
        $tenantId = $this->resolveTenantIdForRowScope();

        return [
            'tenant_id' => $this->user()->isGlobalAdmin()
                ? ['required', 'integer', 'exists:tenants,id']
                : ['prohibited'],
            'code' => [
                'required',
                'string',
                'max:64',
                Rule::unique('partner_programs', 'code')->where(fn ($q) => $q->where('tenant_id', $tenantId)),
            ],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'tier_level' => ['required', 'integer', 'min:0', 'max:255'],
            'status' => ['sometimes', 'string', Rule::in([PartnerProgram::STATUS_ACTIVE, PartnerProgram::STATUS_INACTIVE])],
            'default_commission_percent' => ['required', 'numeric', 'min:0', 'max:100'],
            'rules' => ['nullable', 'array'],
            'metadata' => ['nullable', 'array'],
            'is_template' => ['sometimes', 'boolean'],
        ];
    }

    private function resolveTenantIdForRowScope(): int
    {
        if ($this->user()->isGlobalAdmin()) {
            return (int) $this->input('tenant_id');
        }

        return (int) $this->user()->tenant_id;
    }
}
