<?php

namespace App\Http\Requests\Prm;

use App\Models\PartnerProgram;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePartnerProgramStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user();
    }

    public function rules(): array
    {
        return [
            'status' => ['required', 'string', Rule::in([PartnerProgram::STATUS_ACTIVE, PartnerProgram::STATUS_INACTIVE])],
        ];
    }
}
