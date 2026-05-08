<?php

namespace App\Http\Requests\Admin\Referral;

use Illuminate\Foundation\Http\FormRequest;

class ProcessPayoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // guarded by `admin` middleware on the route
    }

    public function rules(): array
    {
        return [
            'success' => ['required', 'boolean'],
            'notes'   => [
                $this->boolean('success') ? 'nullable' : 'required',
                'string',
                'max:1000',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'notes.required' => 'A rejection reason is required when failing a payout.',
        ];
    }
}
