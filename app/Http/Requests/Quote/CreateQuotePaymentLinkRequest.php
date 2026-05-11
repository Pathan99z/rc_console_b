<?php

namespace App\Http\Requests\Quote;

use Illuminate\Foundation\Http\FormRequest;

class CreateQuotePaymentLinkRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user();
    }

    public function rules(): array
    {
        return [
            'expires_at' => ['nullable', 'date', 'after:now'],
        ];
    }
}
