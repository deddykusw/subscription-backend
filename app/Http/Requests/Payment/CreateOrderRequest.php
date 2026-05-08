<?php

namespace App\Http\Requests\Payment;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'user_id' => ['required', 'integer', Rule::exists('users', 'id')],
            'plan_id' => ['required', 'integer', Rule::exists('subscription_plans', 'id')],
        ];
    }

    /**
     * The user_id in the body must match the authenticated user.
     * Prevents one user from creating orders charged to another account.
     */
    protected function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ((int) $this->input('user_id') !== $this->user()?->id) {
                $validator->errors()->add(
                    'user_id',
                    'The user_id must match your authenticated account.',
                );
            }
        });
    }

    public function messages(): array
    {
        return [
            'plan_id.exists' => 'The selected subscription plan does not exist.',
        ];
    }
}
