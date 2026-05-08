<?php

namespace App\Http\Requests\Payment;

use Illuminate\Foundation\Http\FormRequest;

class VerifyPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Route is protected by the `admin` middleware — any authenticated admin may verify.
        return true;
    }

    public function rules(): array
    {
        return [
            'is_verified' => ['required', 'boolean'],
            // Notes are required when rejecting so the user knows why.
            'notes'       => [
                $this->boolean('is_verified') ? 'nullable' : 'required',
                'string',
                'max:1000',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'notes.required' => 'A rejection reason is required when rejecting a payment.',
        ];
    }
}
