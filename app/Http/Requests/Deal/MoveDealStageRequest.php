<?php

namespace App\Http\Requests\Deal;

use Illuminate\Foundation\Http\FormRequest;

class MoveDealStageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user();
    }

    public function rules(): array
    {
        return [
            'pipeline_stage_id' => ['required', 'integer', 'exists:pipeline_stages,id'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
