<?php

namespace App\Http\Requests\Pipeline;

use Illuminate\Foundation\Http\FormRequest;

class CreatePipelineStageRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return (bool) ($user?->isCompanyAdmin() || $user?->isGlobalAdmin());
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'stage_order' => ['required', 'integer', 'min:1'],
            'status' => ['nullable', 'integer', 'in:0,1'],
        ];
    }
}
