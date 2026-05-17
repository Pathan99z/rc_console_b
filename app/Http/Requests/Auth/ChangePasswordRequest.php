<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Validator;

final class ChangePasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'password_confirmation' => ['required', 'string', 'min:8'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            $user = $this->user();
            if (! $user || $v->errors()->isNotEmpty()) {
                return;
            }

            $current = (string) $this->input('current_password');
            $new = (string) $this->input('password');

            if ($current !== '' && ! Hash::check($current, (string) $user->password)) {
                $v->errors()->add('current_password', 'The current password is incorrect.');
            }

            if ($new !== '' && Hash::check($new, (string) $user->password)) {
                $v->errors()->add('password', 'The new password must be different from your current password.');
            }
        });
    }
}
