<?php

namespace App\Http\Requests\Quote;

use Illuminate\Foundation\Http\FormRequest;

class SendQuoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user();
    }

    public function rules(): array
    {
        return [
            'email' => ['nullable', 'email', 'max:255'],
            'message' => ['nullable', 'string', 'max:5000'],
            'layout_code' => ['nullable', 'string', 'in:classic,modern,minimal,detailed'],
            'attach_pdf' => ['nullable', 'boolean'],
        ];
    }
}
