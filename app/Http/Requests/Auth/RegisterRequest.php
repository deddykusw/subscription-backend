<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'external_user_id' => [
                'required',
                'string',
                'max:255',
                Rule::unique('users', 'external_user_id'),
            ],
            'email' => [
                'required',
                'string',
                'email',
                'max:255',
                Rule::unique('users', 'email'),
            ],
            'name'          => ['required', 'string', 'max:255'],
            'device_name'   => ['required', 'string', 'max:255'],
            // Optional: referral code entered during registration
            'referral_code' => ['nullable', 'string', 'max:50'],
        ];
    }

    public function messages(): array
    {
        return [
            'external_user_id.unique' => 'This external user ID is already registered.',
            'email.unique'            => 'This email address is already registered.',
        ];
    }
}
