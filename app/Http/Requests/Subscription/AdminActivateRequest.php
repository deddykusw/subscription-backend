<?php

namespace App\Http\Requests\Subscription;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AdminActivateRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Route is protected by the `admin` middleware — any authenticated
        // admin may manually activate a subscription for any user.
        return true;
    }

    public function rules(): array
    {
        return [
            'user_id' => ['required', 'integer', Rule::exists('users', 'id')],
            'plan_id' => ['required', 'integer', Rule::exists('subscription_plans', 'id')],
        ];
    }

    public function messages(): array
    {
        return [
            'user_id.exists' => 'The specified user does not exist.',
            'plan_id.exists' => 'The specified subscription plan does not exist.',
        ];
    }
}
