<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;

class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'external_user_id' => ['nullable', 'string', 'max:255'],
            'email'            => ['nullable', 'string', 'email', 'max:255'],
            'device_name'      => ['required', 'string', 'max:255'],
        ];
    }

    /**
     * Require at least one identifier to be present.
     * Runs after the field-level rules pass.
     */
    protected function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if (! $this->filled('external_user_id') && ! $this->filled('email')) {
                $validator->errors()->add(
                    'external_user_id',
                    'Either external_user_id or email is required.',
                );
            }
        });
    }

    public function messages(): array
    {
        return [
            'device_name.required' => 'A device name is required to issue an access token.',
        ];
    }
}
