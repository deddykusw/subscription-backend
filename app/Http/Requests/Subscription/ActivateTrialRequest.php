<?php

namespace App\Http\Requests\Subscription;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ActivateTrialRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'user_id' => ['required', 'integer', Rule::exists('users', 'id')],
        ];
    }

    /**
     * The user_id in the body must belong to the authenticated user.
     * Enforcing this in rules() (not the controller) keeps the authorization
     * logic close to the input validation so it can't be accidentally skipped.
     */
    public function withValidator(\Illuminate\Contracts\Validation\Validator $validator): void
    {
        $validator->after(function (\Illuminate\Contracts\Validation\Validator $validator) {
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
            'user_id.required' => 'A user_id is required to activate the trial.',
            'user_id.exists'   => 'The specified user does not exist.',
        ];
    }
}
